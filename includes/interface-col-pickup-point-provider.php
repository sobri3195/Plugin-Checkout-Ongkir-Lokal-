<?php

if (! defined('ABSPATH')) {
    exit;
}

interface COL_Pickup_Point_Provider_Interface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_pickup_points(string $city, string $postcode): array;
}
