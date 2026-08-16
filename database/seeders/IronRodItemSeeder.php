<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class IronRodItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            $grade = $row['grade'];
            $diameter = $row['diameter_mm'] ?? null;
            $code = $row['code'] ?? $this->codeFor($grade, $diameter, $row['name'] ?? null);
            $name = $row['name'] ?? $this->nameFor($grade, $diameter);

            Item::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'grade' => $grade,
                    'diameter_mm' => $diameter,
                    'unit' => 'kg',
                    'purchase_price' => $row['purchase_price'] ?? 0,
                    'category' => 'raw_material',
                    'status' => 'active',
                ],
            );
        }
    }

  private function codeFor(string $grade, ?float $diameter, ?string $name): string
    {
        if ($name !== null && str_contains(strtoupper($name), 'PIPE')) {
            return strtoupper(str_replace([' ', '.'], ['-', ''], $name));
        }

        $slug = str_replace(' ', '', strtoupper($grade));

        return $diameter === null
            ? $slug
            : $slug.'-'.(int) $diameter;
    }

    private function nameFor(string $grade, ?float $diameter): string
    {
        return $diameter === null
            ? strtoupper($grade)
            : strtoupper($grade).' DIA '.(fmod($diameter, 1.0) === 0.0 ? (int) $diameter : $diameter);
    }

    /** @return list<array{grade: string, diameter_mm?: float|null, purchase_price?: float, name?: string, code?: string}> */
    private function catalog(): array
    {
        return [
            ['grade' => 'EN18', 'diameter_mm' => 42, 'purchase_price' => 58.00],
            ['grade' => 'EN18', 'diameter_mm' => 50, 'purchase_price' => 59.44],
            ['grade' => 'EN18', 'diameter_mm' => 56, 'purchase_price' => 58.43],
            ['grade' => 'EN18', 'diameter_mm' => 60, 'purchase_price' => 58.00],
            ['grade' => 'EN19', 'diameter_mm' => 25],
            ['grade' => 'EN19', 'diameter_mm' => 32],
            ['grade' => 'EN19', 'diameter_mm' => 40, 'purchase_price' => 71.22],
            ['grade' => 'EN19', 'diameter_mm' => 45, 'purchase_price' => 69.49],
            ['grade' => 'EN19', 'diameter_mm' => 48, 'purchase_price' => 67.00],
            ['grade' => 'EN19', 'diameter_mm' => 50, 'purchase_price' => 66.68],
            ['grade' => 'EN19', 'diameter_mm' => 53, 'purchase_price' => 68.67],
            ['grade' => 'EN19', 'diameter_mm' => 56],
            ['grade' => 'EN19', 'diameter_mm' => 60, 'purchase_price' => 66.61],
            ['grade' => 'EN19', 'diameter_mm' => 63, 'purchase_price' => 66.48],
            ['grade' => 'EN19', 'diameter_mm' => 70, 'purchase_price' => 67.21],
            ['grade' => 'EN19', 'diameter_mm' => 75, 'purchase_price' => 67.37],
            ['grade' => 'EN19', 'diameter_mm' => 80, 'purchase_price' => 67.27],
            ['grade' => 'EN19', 'diameter_mm' => 85, 'purchase_price' => 67.50],
            ['grade' => 'EN19', 'diameter_mm' => 95, 'purchase_price' => 67.73],
            ['grade' => 'EN24', 'diameter_mm' => 56, 'purchase_price' => 90.00],
            ['grade' => 'EN24', 'diameter_mm' => 65, 'purchase_price' => 84.36],
            ['grade' => 'EN24', 'diameter_mm' => 70, 'purchase_price' => 84.00],
            ['grade' => 'EN8D', 'diameter_mm' => 25],
            ['grade' => 'EN8D', 'diameter_mm' => 28, 'purchase_price' => 55.55],
            ['grade' => 'EN8D', 'diameter_mm' => 32, 'purchase_price' => 55.09],
            ['grade' => 'EN8D', 'diameter_mm' => 42, 'purchase_price' => 54.92],
            ['grade' => 'EN8D', 'diameter_mm' => 45, 'purchase_price' => 53.68],
            ['grade' => 'EN8D', 'diameter_mm' => 53, 'purchase_price' => 55.69],
            ['grade' => 'EN8D', 'diameter_mm' => 56, 'purchase_price' => 52.50],
            ['grade' => 'EN8D', 'diameter_mm' => 58, 'purchase_price' => 54.04],
            ['grade' => 'EN8D', 'diameter_mm' => 63, 'purchase_price' => 55.00],
            ['grade' => 'EN8D', 'diameter_mm' => 66, 'purchase_price' => 58.14],
            ['grade' => 'EN8D', 'diameter_mm' => 70, 'purchase_price' => 62.50],
            ['grade' => 'EN8D', 'diameter_mm' => 72, 'purchase_price' => 58.51],
            ['grade' => 'EN8D', 'diameter_mm' => 80, 'purchase_price' => 58.51],
            ['grade' => 'EN8D', 'diameter_mm' => 100, 'purchase_price' => 60.58],
            ['grade' => 'EN8D', 'diameter_mm' => 115, 'purchase_price' => 61.71],
            ['grade' => 'HC', 'diameter_mm' => 66, 'purchase_price' => 52.00],
            ['grade' => 'HC', 'diameter_mm' => 95, 'purchase_price' => 55.58],
            ['grade' => 'HC', 'diameter_mm' => 105, 'purchase_price' => 55.47],
            ['grade' => 'HC', 'diameter_mm' => 110],
            ['grade' => 'HC', 'diameter_mm' => 125, 'purchase_price' => 58.68],
            ['grade' => 'HC', 'diameter_mm' => 150],
            ['grade' => 'HC', 'diameter_mm' => 152, 'purchase_price' => 58.57],
            ['grade' => 'HEX', 'diameter_mm' => 75],
            ['grade' => 'MS', 'diameter_mm' => 40, 'purchase_price' => 52.00],
            ['grade' => 'MS', 'diameter_mm' => 71, 'purchase_price' => 78.00],
            ['grade' => 'MS', 'diameter_mm' => 72, 'purchase_price' => 53.75],
            [
                'grade' => 'MS',
                'name' => 'MS PIPE 82X58.5',
                'code' => 'MS-PIPE-82X58',
                'diameter_mm' => 82,
                'purchase_price' => 78.00,
            ],
        ];
    }
}
