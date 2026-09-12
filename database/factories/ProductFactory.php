<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    private const BRANDS = [
        'Logitech', 'Sony', 'Dell', 'HP', 'Anker', 'Samsung', 'JBL', 'Razer', 'Apple', 'ASUS',
    ];

    private const PRODUCT_TYPES = [
        'Wireless Mouse', 'Mechanical Keyboard', '27" 4K Monitor', 'USB-C Hub',
        'Noise Cancelling Headphones', 'Bluetooth Speaker', 'HD Webcam 1080p',
        'Adjustable Laptop Stand', 'External SSD 1TB', 'Portable Power Bank',
        'Smartwatch', 'Wireless Charging Pad', 'Gaming Mouse Pad', 'HDMI Cable 2m',
        'Standing Desk Converter', 'Wi-Fi 6 Router', '4K Action Camera',
        'Tablet Stylus Pen', 'Screen Protector (3-Pack)', 'Laptop Backpack',
        'USB-C Docking Station', 'Wireless Earbuds', 'Smartphone Gimbal Stabilizer',
        'LED Ring Light', 'Graphics Drawing Tablet', 'Mini Portable Projector',
        'Car Phone Mount', 'Cable Management Organizer',
    ];

    public function definition(): array
    {
        $brand = fake()->randomElement(self::BRANDS);
        $type = fake()->randomElement(self::PRODUCT_TYPES);
        $brandCode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $brand), 0, 3));

        return [
            'name' => "{$brand} {$type}",
            'sku' => "{$brandCode}-".fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 500, 2000),
            'stock' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
