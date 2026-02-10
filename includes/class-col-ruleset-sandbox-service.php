<?php

if (! defined('ABSPATH')) {
    exit;
}

class COL_Ruleset_Sandbox_Service
{
    private string $ruleset_table;
    private string $audit_table;
    private string $simulation_table;

    public function __construct(private wpdb $wpdb, private COL_Logger $logger)
    {
        $this->ruleset_table = $this->wpdb->prefix . 'col_rulesets';
        $this->audit_table = $this->wpdb->prefix . 'col_ruleset_audit';
        $this->simulation_table = $this->wpdb->prefix . 'col_rule_simulations';
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('wp_ajax_col_save_ruleset_draft', [$this, 'ajax_save_ruleset_draft']);
        add_action('wp_ajax_col_submit_ruleset_approval', [$this, 'ajax_submit_ruleset_approval']);
        add_action('wp_ajax_col_approve_ruleset', [$this, 'ajax_approve_ruleset']);
        add_action('wp_ajax_col_publish_ruleset', [$this, 'ajax_publish_ruleset']);
        add_action('wp_ajax_col_start_ruleset_simulation', [$this, 'ajax_start_ruleset_simulation']);
        add_action('wp_ajax_col_get_simulation_status', [$this, 'ajax_get_simulation_status']);
        add_action('col_run_ruleset_simulation_job', [$this, 'process_simulation_job'], 10, 1);
    }

    public function register_admin_menu(): void
    {
        add_submenu_page(
            'checkout-ongkir-lokal',
            __('Rule Simulation Sandbox', 'checkout-ongkir-lokal'),
            __('Rule Sandbox', 'checkout-ongkir-lokal'),
            'manage_woocommerce',
            'col-rule-sandbox',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $draft = $this->get_latest_ruleset_by_status('draft');
        $published = $this->get_latest_published_ruleset();
        $diff = $this->build_diff($published['rules_json'] ?? '', $draft['rules_json'] ?? '');

        echo '<div class="wrap">';
        echo '<h1>Rule Simulation Sandbox</h1>';
        echo '<p>Buat draft ruleset, simulasi ke order historis 30 hari terakhir, lalu publish jika sudah approved.</p>';
        echo '<input type="hidden" id="col_ruleset_nonce" value="' . esc_attr(wp_create_nonce('col_ruleset_nonce')) . '">';

        echo '<h2>Draft Ruleset</h2>';
        echo '<p>Status draft saat ini: <strong>' . esc_html($draft['status'] ?? 'belum ada') . '</strong></p>';
        echo '<textarea id="col_ruleset_json" rows="14" class="large-text code">' . esc_textarea($draft['rules_json'] ?? wp_json_encode($this->default_ruleset_payload(), JSON_PRETTY_PRINT)) . '</textarea>';
        echo '<p>';
        echo '<button class="button button-primary" id="col-save-draft">Simpan Draft Baru (Versioning)</button> ';
        echo '<button class="button" id="col-submit-approval">Submit untuk Approval</button> ';
        echo '<button class="button" id="col-approve-draft">Setujui Draft</button> ';
        echo '<button class="button button-secondary" id="col-publish-ruleset">Publish Ruleset Approved</button>';
        echo '</p>';

        echo '<h2>Diff Viewer (Published vs Draft)</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Published</th><th>Draft</th></tr></thead><tbody>';
        if (empty($diff)) {
            echo '<tr><td colspan="3">Tidak ada perubahan.</td></tr>';
        } else {
            foreach ($diff as $row) {
                echo '<tr><td>' . esc_html($row['key']) . '</td><td><code>' . esc_html((string) $row['old']) . '</code></td><td><code>' . esc_html((string) $row['new']) . '</code></td></tr>';
            }
        }
        echo '</tbody></table>';

        echo '<h2>Simulation Sandbox</h2>';
        echo '<p><button class="button" id="col-start-simulation">Jalankan Simulasi 30 Hari</button></p>';
        echo '<div id="col-simulation-progress" style="margin:8px 0;font-weight:600;"></div>';
        echo '<div id="col-simulation-metrics"></div>';

        $this->render_audit_trail();
        $this->render_inline_script();
        echo '</div>';
    }

    public function ajax_save_ruleset_draft(): void
    {
        $this->assert_ajax_permission();

        $rules_json = isset($_POST['rules_json']) ? wp_unslash($_POST['rules_json']) : '{}';
        $rules = json_decode((string) $rules_json, true);
        if (! is_array($rules)) {
            wp_send_json_error(['message' => 'JSON ruleset tidak valid.'], 400);
        }

        $version = $this->next_version_number();
        $ruleset_id = $this->insert_ruleset($version, 'draft', $rules);
        $this->audit('draft_created', null, $rules, ['ruleset_id' => $ruleset_id, 'version' => $version]);

        wp_send_json_success(['ruleset_id' => $ruleset_id, 'version' => $version, 'status' => 'draft']);
    }

    public function ajax_submit_ruleset_approval(): void
    {
        $this->assert_ajax_permission();
        $draft = $this->get_latest_ruleset_by_status('draft');
        if (! $draft) {
            wp_send_json_error(['message' => 'Draft tidak ditemukan.'], 404);
        }

        $this->update_ruleset_status((int) $draft['id'], 'pending_approval');
        $this->audit('draft_submitted_for_approval', json_decode((string) $draft['rules_json'], true), null, ['ruleset_id' => (int) $draft['id']]);

        wp_send_json_success(['status' => 'pending_approval']);
    }

    public function ajax_approve_ruleset(): void
    {
        $this->assert_ajax_permission();
        $pending = $this->get_latest_ruleset_by_status('pending_approval');
        if (! $pending) {
            wp_send_json_error(['message' => 'Ruleset pending approval tidak ditemukan.'], 404);
        }

        $this->update_ruleset_status((int) $pending['id'], 'approved');
        $this->audit('ruleset_approved', json_decode((string) $pending['rules_json'], true), null, ['ruleset_id' => (int) $pending['id']]);

        wp_send_json_success(['status' => 'approved']);
    }

    public function ajax_publish_ruleset(): void
    {
        $this->assert_ajax_permission();

        $approved = $this->get_latest_ruleset_by_status('approved');
        if (! $approved) {
            wp_send_json_error(['message' => 'Publish hanya diizinkan untuk ruleset berstatus approved.'], 400);
        }

        $this->wpdb->update(
            $this->ruleset_table,
            ['status' => 'archived', 'updated_at' => current_time('mysql')],
            ['status' => 'published'],
            ['%s', '%s'],
            ['%s']
        );

        $this->update_ruleset_status((int) $approved['id'], 'published');
        $this->audit('ruleset_published', json_decode((string) $approved['rules_json'], true), null, ['ruleset_id' => (int) $approved['id']]);

        wp_send_json_success(['status' => 'published']);
    }

    public function ajax_start_ruleset_simulation(): void
    {
        $this->assert_ajax_permission();

        $draft = $this->get_latest_ruleset_by_status('draft');
        if (! $draft) {
            $draft = $this->get_latest_ruleset_by_status('pending_approval');
        }
        if (! $draft) {
            wp_send_json_error(['message' => 'Draft ruleset tidak tersedia untuk simulasi.'], 404);
        }

        $this->wpdb->insert(
            $this->simulation_table,
            [
                'ruleset_id' => (int) $draft['id'],
                'status' => 'queued',
                'progress_percent' => 0,
                'message' => 'Menunggu worker background.',
                'result_json' => wp_json_encode([]),
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s']
        );

        $job_id = (int) $this->wpdb->insert_id;
        wp_schedule_single_event(time() + 1, 'col_run_ruleset_simulation_job', [$job_id]);

        $this->audit('simulation_started', json_decode((string) $draft['rules_json'], true), null, ['job_id' => $job_id, 'ruleset_id' => (int) $draft['id']]);

        wp_send_json_success(['job_id' => $job_id]);
    }

    public function ajax_get_simulation_status(): void
    {
        $this->assert_ajax_permission();
        $job_id = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
        if ($job_id <= 0) {
            wp_send_json_error(['message' => 'job_id tidak valid.'], 400);
        }

        $job = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->simulation_table} WHERE id = %d", $job_id), ARRAY_A);
        if (! $job) {
            wp_send_json_error(['message' => 'Job tidak ditemukan.'], 404);
        }

        wp_send_json_success([
            'status' => $job['status'],
            'progress_percent' => (int) $job['progress_percent'],
            'message' => $job['message'],
            'result' => json_decode((string) $job['result_json'], true),
        ]);
    }

    public function process_simulation_job(int $job_id): void
    {
        $job = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->simulation_table} WHERE id = %d", $job_id), ARRAY_A);
        if (! $job) {
            return;
        }

        $ruleset = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->ruleset_table} WHERE id = %d", (int) $job['ruleset_id']), ARRAY_A);
        if (! $ruleset) {
            $this->update_simulation($job_id, 'failed', 100, 'Ruleset tidak ditemukan.', []);
            return;
        }

        $rules = json_decode((string) $ruleset['rules_json'], true);
        if (! is_array($rules)) {
            $this->update_simulation($job_id, 'failed', 100, 'Ruleset tidak valid.', []);
            return;
        }

        $this->update_simulation($job_id, 'running', 10, 'Mengambil sampel order historis 30 hari.');
        $orders = $this->load_historical_orders();
        $total_orders = count($orders);
        if ($total_orders === 0) {
            $this->update_simulation($job_id, 'completed', 100, 'Tidak ada order historis untuk periode simulasi.', [
                'sample_size' => 0,
                'cod_approval_rate' => 0,
                'average_shipping_fee' => 0,
                'estimated_margin' => 0,
                'potential_rto' => 0,
            ]);
            return;
        }

        $approved_cod = 0;
        $shipping_sum = 0.0;
        $margin_sum = 0.0;
        $rto_risk_count = 0;

        foreach ($orders as $index => $order) {
            $result = $this->simulate_order($order, $rules);
            $approved_cod += $result['allow_cod'] ? 1 : 0;
            $shipping_sum += $result['shipping_fee'];
            $margin_sum += $result['margin'];
            $rto_risk_count += $result['potential_rto'] ? 1 : 0;

            if ($index % 5 === 0) {
                $progress = min(95, 10 + (int) floor((($index + 1) / $total_orders) * 80));
                $this->update_simulation($job_id, 'running', $progress, 'Memproses order ' . ($index + 1) . ' dari ' . $total_orders . '.');
            }
        }

        $metrics = [
            'sample_size' => $total_orders,
            'cod_approval_rate' => round(($approved_cod / $total_orders) * 100, 2),
            'average_shipping_fee' => round($shipping_sum / $total_orders, 2),
            'estimated_margin' => round($margin_sum / $total_orders, 2),
            'potential_rto' => round(($rto_risk_count / $total_orders) * 100, 2),
        ];

        $this->update_simulation($job_id, 'completed', 100, 'Simulasi selesai.', $metrics);
        $this->audit('simulation_completed', $rules, $metrics, ['job_id' => $job_id, 'ruleset_id' => (int) $ruleset['id']]);
    }

    private function load_historical_orders(): array
    {
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'limit' => 200,
                'date_created' => '>' . (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
                'status' => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled'],
                'return' => 'objects',
            ]);

            $result = [];
            foreach ($orders as $order) {
                $result[] = [
                    'total' => (float) $order->get_total(),
                    'shipping_total' => (float) $order->get_shipping_total(),
                    'payment_method' => (string) $order->get_payment_method(),
                    'status' => (string) $order->get_status(),
                    'address_1' => (string) $order->get_shipping_address_1(),
                    'postcode' => (string) $order->get_shipping_postcode(),
                    'city' => (string) $order->get_shipping_city(),
                    'hour' => (int) $order->get_date_created()->date('G'),
                ];
            }

            return $result;
        }

        return [];
    }

    private function simulate_order(array $order, array $rules): array
    {
        $min_order = (float) ($rules['min_order_cod'] ?? 75000);
        $deny_cities = is_array($rules['deny_cities'] ?? null) ? $rules['deny_cities'] : [];
        $block_fragile = (bool) ($rules['block_fragile'] ?? false);
        $shipping_multiplier = (float) ($rules['shipping_multiplier'] ?? 1);
        $shipping_surcharge = (float) ($rules['shipping_surcharge'] ?? 0);
        $rto_threshold = (int) ($rules['rto_threshold'] ?? 70);

        $allow_cod = $order['total'] >= $min_order && ! in_array($order['city'], $deny_cities, true);
        if ($block_fragile && strpos(strtolower((string) $order['address_1']), 'fragile') !== false) {
            $allow_cod = false;
        }

        $shipping_fee = max(0, ($order['shipping_total'] * $shipping_multiplier) + $shipping_surcharge);
        $estimated_cogs = $order['total'] * 0.65;
        $margin = $order['total'] - $estimated_cogs - $shipping_fee;

        $risk_score = 20;
        $risk_score += $allow_cod ? 20 : 35;
        $risk_score += ((int) $order['hour'] >= 22 || (int) $order['hour'] <= 4) ? 20 : 0;
        $risk_score += in_array($order['status'], ['cancelled', 'wc-cancelled'], true) ? 25 : 0;

        return [
            'allow_cod' => $allow_cod,
            'shipping_fee' => $shipping_fee,
            'margin' => $margin,
            'potential_rto' => $risk_score >= $rto_threshold,
        ];
    }

    private function render_audit_trail(): void
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->audit_table} ORDER BY id DESC LIMIT 20", ARRAY_A);
        echo '<h2>Audit Trail</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Meta</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="4">Belum ada audit log.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $user = get_userdata((int) $row['actor_id']);
                $name = $user ? $user->display_name : ('User#' . (int) $row['actor_id']);
                echo '<tr><td>' . esc_html((string) $row['created_at']) . '</td><td>' . esc_html($name) . '</td><td>' . esc_html((string) $row['action']) . '</td><td><code>' . esc_html((string) $row['meta_json']) . '</code></td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    private function render_inline_script(): void
    {
        echo '<script>
        (function(){
            const nonce = document.getElementById("col_ruleset_nonce").value;
            const ajaxUrl = "' . esc_js(admin_url('admin-ajax.php')) . '";
            let activeJobId = null;

            const request = (action, payload = {}) => {
                const body = new URLSearchParams({action, _ajax_nonce: nonce, ...payload});
                return fetch(ajaxUrl, {method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/x-www-form-urlencoded"}, body})
                  .then(r => r.json());
            };

            document.getElementById("col-save-draft").addEventListener("click", function(){
                request("col_save_ruleset_draft", {rules_json: document.getElementById("col_ruleset_json").value}).then(resp => {
                    alert(resp.success ? `Draft tersimpan v${resp.data.version}` : (resp.data.message || "Gagal menyimpan draft"));
                    if (resp.success) location.reload();
                });
            });

            document.getElementById("col-submit-approval").addEventListener("click", function(){
                request("col_submit_ruleset_approval").then(resp => {
                    alert(resp.success ? "Draft dikirim ke approval" : (resp.data.message || "Gagal submit approval"));
                    if (resp.success) location.reload();
                });
            });

            document.getElementById("col-approve-draft").addEventListener("click", function(){
                request("col_approve_ruleset").then(resp => {
                    alert(resp.success ? "Ruleset approved" : (resp.data.message || "Gagal approve"));
                    if (resp.success) location.reload();
                });
            });

            document.getElementById("col-publish-ruleset").addEventListener("click", function(){
                request("col_publish_ruleset").then(resp => {
                    alert(resp.success ? "Ruleset published" : (resp.data.message || "Publish ditolak"));
                    if (resp.success) location.reload();
                });
            });

            document.getElementById("col-start-simulation").addEventListener("click", function(){
                request("col_start_ruleset_simulation").then(resp => {
                    if (!resp.success) {
                        alert(resp.data.message || "Gagal memulai simulasi");
                        return;
                    }
                    activeJobId = resp.data.job_id;
                    poll();
                });
            });

            function poll(){
                if (!activeJobId) return;
                const params = new URLSearchParams({action: "col_get_simulation_status", _ajax_nonce: nonce, job_id: String(activeJobId)});
                fetch(`${ajaxUrl}?${params.toString()}`, {credentials: "same-origin"}).then(r => r.json()).then(resp => {
                    if (!resp.success) {
                        document.getElementById("col-simulation-progress").innerText = "Gagal membaca status.";
                        return;
                    }
                    const d = resp.data;
                    document.getElementById("col-simulation-progress").innerText = `${d.progress_percent}% - ${d.message}`;
                    if (d.status === "completed") {
                        document.getElementById("col-simulation-metrics").innerHTML = `<ul>
                            <li>Sample order: <strong>${d.result.sample_size}</strong></li>
                            <li>COD approval rate: <strong>${d.result.cod_approval_rate}%</strong></li>
                            <li>Rata-rata ongkir: <strong>${d.result.average_shipping_fee}</strong></li>
                            <li>Estimasi margin: <strong>${d.result.estimated_margin}</strong></li>
                            <li>Potensi RTO: <strong>${d.result.potential_rto}%</strong></li>
                        </ul>`;
                        return;
                    }
                    if (d.status === "failed") {
                        return;
                    }
                    setTimeout(poll, 1500);
                });
            }
        })();
        </script>';
    }

    private function assert_ajax_permission(): void
    {
        check_ajax_referer('col_ruleset_nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
    }

    private function get_latest_ruleset_by_status(string $status): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->ruleset_table} WHERE status = %s ORDER BY version DESC LIMIT 1",
            $status
        ), ARRAY_A);

        return $row ?: null;
    }

    private function get_latest_published_ruleset(): ?array
    {
        return $this->get_latest_ruleset_by_status('published');
    }

    private function insert_ruleset(int $version, string $status, array $rules): int
    {
        $this->wpdb->insert(
            $this->ruleset_table,
            [
                'version' => $version,
                'status' => $status,
                'rules_json' => wp_json_encode($rules),
                'created_by' => get_current_user_id(),
                'approved_by' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        return (int) $this->wpdb->insert_id;
    }

    private function update_ruleset_status(int $id, string $status): void
    {
        $payload = ['status' => $status, 'updated_at' => current_time('mysql')];
        $formats = ['%s', '%s'];
        if ($status === 'approved' || $status === 'published') {
            $payload['approved_by'] = get_current_user_id();
            $formats[] = '%d';
        }

        $this->wpdb->update($this->ruleset_table, $payload, ['id' => $id], $formats, ['%d']);
    }

    private function next_version_number(): int
    {
        $max = (int) $this->wpdb->get_var("SELECT MAX(version) FROM {$this->ruleset_table}");
        return $max + 1;
    }

    private function build_diff(string $old_json, string $new_json): array
    {
        $old = json_decode($old_json, true);
        $new = json_decode($new_json, true);
        if (! is_array($old)) {
            $old = [];
        }
        if (! is_array($new)) {
            $new = [];
        }

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];
        foreach ($keys as $key) {
            $old_value = $old[$key] ?? '';
            $new_value = $new[$key] ?? '';
            if (wp_json_encode($old_value) !== wp_json_encode($new_value)) {
                $diff[] = [
                    'key' => (string) $key,
                    'old' => is_scalar($old_value) ? (string) $old_value : wp_json_encode($old_value),
                    'new' => is_scalar($new_value) ? (string) $new_value : wp_json_encode($new_value),
                ];
            }
        }

        return $diff;
    }

    private function audit(string $action, ?array $before, ?array $after, array $meta = []): void
    {
        $this->wpdb->insert(
            $this->audit_table,
            [
                'ruleset_id' => (int) ($meta['ruleset_id'] ?? 0),
                'actor_id' => get_current_user_id(),
                'action' => $action,
                'before_json' => wp_json_encode($before ?? []),
                'after_json' => wp_json_encode($after ?? []),
                'meta_json' => wp_json_encode($meta),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        $this->logger->info('ruleset_audit', 'Audit action ruleset tercatat', [
            'action' => $action,
            'meta' => $meta,
        ]);
    }

    private function update_simulation(int $job_id, string $status, int $progress_percent, string $message, array $result): void
    {
        $this->wpdb->update(
            $this->simulation_table,
            [
                'status' => $status,
                'progress_percent' => max(0, min(100, $progress_percent)),
                'message' => $message,
                'result_json' => wp_json_encode($result),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $job_id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }

    private function default_ruleset_payload(): array
    {
        return [
            'min_order_cod' => 75000,
            'deny_cities' => ['Kab. Kepulauan Mentawai'],
            'block_fragile' => true,
            'shipping_multiplier' => 1,
            'shipping_surcharge' => 0,
            'rto_threshold' => 70,
        ];
    }
}
