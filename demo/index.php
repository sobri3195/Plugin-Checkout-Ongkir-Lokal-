<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower(trim($title));
        return preg_replace('/[^a-z0-9]+/', '-', $title) ?: '';
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type)
    {
        if ($type === 'G') {
            return 14;
        }

        return gmdate('Y-m-d H:i:s');
    }
}

require_once dirname(__DIR__) . '/includes/class-col-origin-repository.php';
require_once dirname(__DIR__) . '/includes/class-col-shipment-planner.php';
require_once dirname(__DIR__) . '/includes/class-col-shipment-rate-aggregator.php';
require_once dirname(__DIR__) . '/includes/class-col-packaging-optimizer.php';
require_once dirname(__DIR__) . '/includes/class-col-settings.php';
require_once dirname(__DIR__) . '/includes/class-col-logger.php';
require_once dirname(__DIR__) . '/includes/class-col-rule-engine.php';
require_once dirname(__DIR__) . '/includes/class-col-address-intelligence.php';

final class DemoOriginRepository extends COL_Origin_Repository
{
    public function __construct(private array $warehouses, private array $map)
    {
    }

    public function get_active_warehouses(): array
    {
        return $this->warehouses;
    }

    public function get_product_origin_map(array $product_ids): array
    {
        return $this->map;
    }
}

$warehouseRepo = new DemoOriginRepository(
    [
        ['id' => 1, 'name' => 'Gudang Jakarta', 'region_code' => 'JKT', 'priority' => 1],
        ['id' => 2, 'name' => 'Gudang Bandung', 'region_code' => 'BDG', 'priority' => 2],
    ],
    [
        101 => [
            ['warehouse_id' => 1, 'stock_qty' => 1, 'priority' => 1, 'is_fallback' => false],
            ['warehouse_id' => 2, 'stock_qty' => 5, 'priority' => 2, 'is_fallback' => true],
        ],
        202 => [
            ['warehouse_id' => 1, 'stock_qty' => 2, 'priority' => 1, 'is_fallback' => false],
            ['warehouse_id' => 2, 'stock_qty' => 2, 'priority' => 2, 'is_fallback' => false],
        ],
    ]
);

$cartItems = [
    ['product_id' => 101, 'quantity' => 2, 'unit_weight_gram' => 450],
    ['product_id' => 202, 'quantity' => 1, 'unit_weight_gram' => 1200],
];

$planner = new COL_Shipment_Planner($warehouseRepo);
$shipmentPlan = $planner->build_plan($cartItems);

$optimizer = new COL_Packaging_Optimizer();
$packagingResult = $optimizer->optimize(
    $cartItems,
    [
        ['id' => 'small', 'name' => 'Small Box', 'inner_length_cm' => 20, 'inner_width_cm' => 15, 'inner_height_cm' => 10, 'max_weight_gram' => 2000],
        ['id' => 'medium', 'name' => 'Medium Box', 'inner_length_cm' => 30, 'inner_width_cm' => 25, 'inner_height_cm' => 15, 'max_weight_gram' => 5000],
    ],
    ['jne' => 6000, 'default' => 6000],
    ['length' => 10, 'width' => 10, 'height' => 10]
);

$aggregator = new COL_Shipment_Rate_Aggregator();
$rateAggregation = $aggregator->aggregate([
    [
        ['courier' => 'JNE', 'service' => 'REG', 'price' => 18000, 'eta_label' => '2-3 hari'],
        ['courier' => 'JNE', 'service' => 'YES', 'price' => 26000, 'eta_label' => '1 hari'],
    ],
    [
        ['courier' => 'JNE', 'service' => 'REG', 'price' => 9000, 'eta_label' => '2-3 hari'],
        ['courier' => 'JNE', 'service' => 'YES', 'price' => 17000, 'eta_label' => '1 hari'],
    ],
]);

class DemoSettings extends COL_Settings
{
    public function all(): array
    {
        return [
            'cod_risk_weights' => [
                'order_value' => 25,
                'area_distance' => 20,
                'customer_history' => 25,
                'address_quality' => 15,
                'order_time' => 15,
            ],
            'cod_risk_block_threshold' => 80,
            'cod_risk_review_threshold' => 60,
            'cod_risk_risky_hours' => [22, 23, 0, 1, 2, 3, 4],
        ];
    }
}

class DemoLogger extends COL_Logger
{
    public function info(string $event_type, string $message, array $context = []): void
    {
    }

    public function warning(string $event_type, string $message, array $context = []): void
    {
    }

    public function error(string $event_type, string $message, array $context = []): void
    {
    }
}

$ruleEngine = new COL_Rule_Engine(new DemoSettings(), new DemoLogger());
$codDecision = $ruleEngine->evaluate_cod_risk([
    'cart_total' => 950000,
    'destination_district_code' => '3173040',
    'origin_list' => ['3273000'],
    'cancel_count' => 1,
    'rto_count' => 0,
    'completed_count' => 2,
    'address_line' => 'Jl. Panglima Polim No. 77',
    'destination_postcode' => '12130',
    'order_hour' => 23,
]);

$addressIntelligence = new COL_Address_Intelligence();
$addressInsights = $addressIntelligence->suggest('Jl. Panglima Polim No. 77, Kebayoran Baru, Jakarta Selatan 12130');

function render_json(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Checkout Ongkir Lokal</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f6f7fb; color: #1f2a44; }
        .container { max-width: 1080px; margin: 0 auto; padding: 24px; }
        h1 { margin-bottom: 8px; }
        .lead { margin-top: 0; color: #4f5d75; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); gap: 16px; }
        .card { background: #fff; border: 1px solid #dbe2ef; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .card h2 { margin-top: 0; font-size: 18px; }
        pre { background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 10px; overflow: auto; font-size: 12px; }
        .badge { display: inline-block; background: #dbeafe; color: #1d4ed8; border-radius: 999px; padding: 4px 10px; font-size: 12px; margin-bottom: 10px; }
        .steps { background: #fff; border: 1px dashed #a7b3c9; border-radius: 12px; padding: 14px; margin-bottom: 16px; }
        code { background: #eef2ff; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Demo Checkout Ongkir Lokal</h1>
    <p class="lead">Halaman ini menampilkan simulasi flow plugin: shipment planning, packaging, agregasi rate, evaluasi COD, dan analisis alamat.</p>

    <div class="steps">
        <strong>Link Demo Lokal:</strong> <code>http://127.0.0.1:8090/demo/index.php</code><br>
        Jalankan server dari root repo: <code>php -S 127.0.0.1:8090</code>
    </div>

    <div class="grid">
        <div class="card">
            <span class="badge">Shipment Planner</span>
            <h2>Rencana Pengiriman</h2>
            <pre><?= htmlspecialchars(render_json($shipmentPlan), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>

        <div class="card">
            <span class="badge">Packaging Optimizer</span>
            <h2>Optimasi Box & Berat</h2>
            <pre><?= htmlspecialchars(render_json($packagingResult), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>

        <div class="card">
            <span class="badge">Rate Aggregator</span>
            <h2>Agregasi Tarif Multi-Shipment</h2>
            <pre><?= htmlspecialchars(render_json($rateAggregation), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>

        <div class="card">
            <span class="badge">COD Risk</span>
            <h2>Keputusan COD</h2>
            <pre><?= htmlspecialchars(render_json($codDecision), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>

        <div class="card">
            <span class="badge">Address Intelligence</span>
            <h2>Validasi Alamat</h2>
            <pre><?= htmlspecialchars(render_json($addressInsights), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    </div>
</div>
</body>
</html>
