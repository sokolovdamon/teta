<?php

namespace Database\Seeders\Reference;

use App\Modules\Diary\Models\EmotionTag;
use Illuminate\Database\Seeder;

/** DEC-41: default emotion tags for the diary; admins edit the list in ADM-13 (only missing tags are added). */
class EmotionTagSeeder extends Seeder
{
    public const TAGS = [
        ['spokoystvie', 'Спокойствие'], ['radost', 'Радость'], ['blagodarnost', 'Благодарность'], ['interes', 'Интерес'],
        ['nadezhda', 'Надежда'], ['trevoga', 'Тревога'], ['grust', 'Грусть'], ['razdrazhenie', 'Раздражение'],
        ['ustalost', 'Усталость'], ['rasteryannost', 'Растерянность'], ['odinochestvo', 'Одиночество'], ['vina-styd', 'Вина или стыд'],
    ];

    public function run(): void
    {
        foreach (self::TAGS as $i => [$code, $title]) {
            EmotionTag::firstOrCreate(['code' => $code], ['title' => $title, 'sort' => $i, 'is_active' => true]);
        }
    }
}
