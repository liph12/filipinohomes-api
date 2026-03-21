<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class ProvinceSeeder extends Seeder
{
    public function run(): void
    {   
        Schema::disableForeignKeyConstraints();

        DB::table('provinces')->truncate();
        DB::table('provinces')->truncate();

        $provinces = [
        array('id' => '1','name' => 'Abra','code' => 'ABR'),
  array('id' => '2','name' => 'Agusan del Norte','code' => 'ADN'),
  array('id' => '3','name' => 'Agusan del Sur','code' => 'ADS'),
  array('id' => '4','name' => 'Aklan','code' => 'AKL'),
  array('id' => '5','name' => 'Albay','code' => 'ALB'),
  array('id' => '6','name' => 'Antique','code' => 'ATQ'),
  array('id' => '7','name' => 'Apayao','code' => 'APY'),
  array('id' => '8','name' => 'Aurora','code' => 'ARA'),
  array('id' => '9','name' => 'Basilan','code' => 'BSL'),
  array('id' => '10','name' => 'Bataan','code' => 'BAT'),
  array('id' => '11','name' => 'Batanes','code' => 'BTN'),
  array('id' => '12','name' => 'Batangas','code' => 'BTG'),
  array('id' => '13','name' => 'Benguet','code' => 'BEN'),
  array('id' => '14','name' => 'Biliran','code' => 'BLR'),
  array('id' => '15','name' => 'Bohol','code' => 'BOH'),
  array('id' => '16','name' => 'Bukidnon','code' => 'BKN'),
  array('id' => '17','name' => 'Bulacan','code' => 'BLC'),
  array('id' => '18','name' => 'Cagayan','code' => 'CGY'),
  array('id' => '19','name' => 'Camarines Norte','code' => 'CAN'),
  array('id' => '20','name' => 'Camarines Sur','code' => 'CAS'),
  array('id' => '21','name' => 'Camiguin','code' => 'CGM'),
  array('id' => '22','name' => 'Capiz','code' => 'CAP'),
  array('id' => '23','name' => 'Catanduanes','code' => 'CTD'),
  array('id' => '24','name' => 'Cavite','code' => 'CVT'),
  array('id' => '25','name' => 'Cebu','code' => 'CEB'),
  array('id' => '26','name' => 'Compostela Valley','code' => 'CPV'),
  array('id' => '27','name' => 'Cotabato','code' => 'CBO'),
  array('id' => '28','name' => 'Davao del Norte','code' => 'DDN'),
  array('id' => '29','name' => 'Davao del Sur','code' => 'DDS'),
  array('id' => '30','name' => 'Davao Oriental','code' => 'DVO'),
  array('id' => '31','name' => 'Dinagat Islands','code' => 'DNI'),
  array('id' => '32','name' => 'Eastern Samar','code' => 'ETS'),
  array('id' => '33','name' => 'Guimaras','code' => 'GMR'),
  array('id' => '34','name' => 'Ifugao','code' => 'IFG'),
  array('id' => '35','name' => 'Ilocos Norte','code' => 'ILN'),
  array('id' => '36','name' => 'Ilocos Sur','code' => 'ILS'),
  array('id' => '37','name' => 'Iloilo','code' => 'ILO'),
  array('id' => '38','name' => 'Isabela','code' => 'IBL'),
  array('id' => '39','name' => 'Kalinga','code' => 'KLN'),
  array('id' => '40','name' => 'La Union','code' => 'LAU'),
  array('id' => '41','name' => 'Laguna','code' => 'LAG'),
  array('id' => '42','name' => 'Lanao del Norte','code' => 'LDN'),
  array('id' => '43','name' => 'Lanao del Sur','code' => 'LDS'),
  array('id' => '44','name' => 'Leyte','code' => 'LEY'),
  array('id' => '45','name' => 'Maguindanao','code' => 'MGD'),
  array('id' => '46','name' => 'Marinduque','code' => 'MRQ'),
  array('id' => '47','name' => 'Masbate','code' => 'MBT'),
  array('id' => '48','name' => 'Misamis Occidental','code' => 'MOC'),
  array('id' => '49','name' => 'Misamis Oriental','code' => 'MOR'),
  array('id' => '50','name' => 'Mountain Province','code' => 'MPR'),
  array('id' => '51','name' => 'Negros Occidental','code' => 'NOC'),
  array('id' => '52','name' => 'Negros Oriental','code' => 'NOR'),
  array('id' => '53','name' => 'Northern Samar','code' => 'NOS'),
  array('id' => '54','name' => 'Nueva Ecija','code' => 'NUE'),
  array('id' => '55','name' => 'Nueva Vizcaya','code' => 'NUV'),
  array('id' => '56','name' => 'Occidental Mindoro','code' => 'OCM'),
  array('id' => '57','name' => 'Oriental Mindoro','code' => 'ORM'),
  array('id' => '58','name' => 'Palawan','code' => 'PAL'),
  array('id' => '59','name' => 'Pampanga','code' => 'PAM'),
  array('id' => '60','name' => 'Pangasinan','code' => 'PANG'),
  array('id' => '61','name' => 'Quezon','code' => 'QUE'),
  array('id' => '62','name' => 'Quirino','code' => 'QUI'),
  array('id' => '63','name' => 'Rizal','code' => 'RIZ'),
  array('id' => '64','name' => 'Romblon','code' => 'ROM'),
  array('id' => '65','name' => 'Northern Samar','code' => 'NSM'),
  array('id' => '66','name' => 'Sarangani','code' => 'SGN'),
  array('id' => '67','name' => 'Siquijor','code' => 'SQR'),
  array('id' => '68','name' => 'Sorsogon','code' => 'SRS'),
  array('id' => '69','name' => 'South Cotabato','code' => 'SCT'),
  array('id' => '70','name' => 'Southern Leyte','code' => 'SLT'),
  array('id' => '71','name' => 'Sultan Kudarat','code' => 'STK'),
  array('id' => '72','name' => 'Sulu','code' => 'SUL'),
  array('id' => '73','name' => 'Surigao del Norte','code' => 'SDN'),
  array('id' => '74','name' => 'Surigao del Sur','code' => 'SDS'),
  array('id' => '75','name' => 'Tarlac','code' => 'TAR'),
  array('id' => '76','name' => 'Tawi-Tawi','code' => 'TAW'),
  array('id' => '77','name' => 'Zambales','code' => 'ZAM'),
  array('id' => '78','name' => 'Zamboanga del Norte','code' => 'ZDN'),
  array('id' => '79','name' => 'Zamboanga del Sur','code' => 'ZDS'),
  array('id' => '80','name' => 'Zamboanga Sibugay','code' => 'ZMS'),
  array('id' => '81','name' => 'Metro Manila','code' => 'MNL'),
  array('id' => '82','name' => 'Southern Samar','code' => 'SSMR'),
  array('id' => '83','name' => 'Samar','code' => 'SAM')
        ];

        foreach (array_chunk($provinces, 100) as $chunk) {
            DB::table('provinces')->insert($chunk);
        }
    }
}