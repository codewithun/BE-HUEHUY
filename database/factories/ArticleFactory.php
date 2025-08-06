<?php

namespace Database\Factories;

use App\Helpers\StringHelper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Article>
 */
class ArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->sentence(6));

        return [
            'picture_source' => '',
            'title' => $title,
            'slug' => StringHelper::uniqueSlug($title),
            'description' => fake()->paragraph(3)
        ];
    }
}
