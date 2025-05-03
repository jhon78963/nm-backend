<?php

namespace App\Size\Seeders;

use App\Size\Models\Size;
use Illuminate\Database\Seeder;

class SizeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $size = new Size();
        $size->description = 'ESTÁNDAR'; // 1
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'XS'; // 2
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'S'; // 3
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'M'; // 4
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'L'; // 5
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'XL'; // 6
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'XXL'; // 7
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = '26'; // 8
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '28'; // 9
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '30'; // 10
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '32'; // 11
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '34'; // 12
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '36'; // 13
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '38'; // 14
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '40'; // 15
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '0'; // 16
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '2'; // 17
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '4'; // 18
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '6'; // 19
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '8'; // 20
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '10'; // 21
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '12'; // 22
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '14'; // 23
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '16'; // 24
        $size->size_type_id = 3;
        $size->save();
    }
}
