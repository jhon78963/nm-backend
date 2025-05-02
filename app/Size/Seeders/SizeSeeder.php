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
        $size->description = 'XS'; // 1
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'S'; // 2
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'M'; // 3
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'L'; // 4
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'XL'; // 5
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = 'XXL'; // 6
        $size->size_type_id = 1;
        $size->save();

        $size = new Size();
        $size->description = '26'; // 7
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '28'; // 8
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '30'; // 9
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '32'; // 10
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '34'; // 11
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '36'; // 12
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '38'; // 13
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '40'; // 14
        $size->size_type_id = 2;
        $size->save();

        $size = new Size();
        $size->description = '0'; // 15
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '2'; // 16
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '4'; // 17
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '6'; // 18
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '8'; // 19
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '10'; // 20
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '12'; // 21
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '14'; // 22
        $size->size_type_id = 3;
        $size->save();

        $size = new Size();
        $size->description = '16'; // 23
        $size->size_type_id = 3;
        $size->save();
    }
}
