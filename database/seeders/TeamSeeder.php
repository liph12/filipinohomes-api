<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class TeamSeeder extends Seeder
{
    public function run(): void
    {   
        Schema::disableForeignKeyConstraints();

        DB::table('teams')->truncate();
        DB::table('teams')->truncate();

        $teams = [
 array('id' => '1','name' => 'Victorious Secret','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '2','name' => 'LR Direct 2','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '3','name' => 'Eagle Team (merged to Team 8)','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '4','name' => 'Team A','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '5','name' => 'Solid LR Team','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/solid-lr-team.png'),
  array('id' => '6','name' => 'Wire Toppers','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '7','name' => 'LR InfiniteAm','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '8','name' => 'LR-Uptown','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '9','name' => 'LR Champ
','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '10','name' => 'LR Alliance','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '40','name' => 'Prosperous Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '12','name' => 'Team G','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '25','name' => 'Vanguard','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '13','name' => 'Team E.Favourites','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '15','name' => 'Team Tycoons','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '16','name' => 'LR Connect Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '26','name' => 'JV-Partners','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '17','name' => 'StarShooters','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '27','name' => 'Team Obregon','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '18','name' => 'LR Realty Masters','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '19','name' => 'Leuterio Realty Bohol','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '20','name' => 'LR International Group','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '21','name' => 'Team A Bacolod','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '23','name' => 'Team X Factor','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '24','name' => 'The Extreme Millionaires','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '28','name' => 'Team 8','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '29','name' => 'Davao Diamond','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '31','name' => 'Starlight Team','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '32','name' => 'LR Power Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '33','name' => 'LR Dynamics','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '34','name' => 'Team E (merged to Solid LR)','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '35','name' => 'Alpha One Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '37','name' => 'Team Royalties','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '39','name' => 'Beautiful Life','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '41','name' => 'LR Fantastic','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '42','name' => 'e-Grace Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '44','name' => 'Filipino Dream Homes Realty Brokerage Corp','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '45','name' => 'LDS Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '46','name' => 'Leuterio Direct','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '47','name' => 'LR Royale Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '49','name' => 'Realty Masters','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '50','name' => 'Team ACES','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '52','name' => 'Las Vegas USA','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '54','name' => 'SkyScrapers','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '55','name' => 'LR Dream Team','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '56','name' => 'LR Heroes','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '57','name' => 'Team Equality','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '58','name' => 'Janet Altamarino','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '59','name' => 'Team Rise','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '60','name' => 'Davao Eagles DIRECT','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '61','name' => 'Team Conquerors','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '62','name' => 'North Cluster Team','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '63','name' => 'LR Tech Squad','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '64','name' => 'BEX Team','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/bex-team.png'),
  array('id' => '65','name' => 'LR Camsur','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '66','name' => 'LR Upgrade','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '67','name' => 'LR Samar','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '68','name' => 'Red Diamonds','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/red-diamonds.jpg'),
  array('id' => '69','name' => 'Dreamchasers','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/dreamchasers.png'),
  array('id' => '70','name' => 'LR Star','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '71','name' => 'Golden Aces','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '72','name' => 'Filipinohomes VIP','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '73','name' => 'Legendary Lions','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '74','name' => 'FH Royals','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '75','name' => 'LR Powerhouse','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '76','name' => 'LR Aces','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '77','name' => 'Elite Team','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/elite-team.png'),
  array('id' => '78','name' => 'Filipino Homes International League','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '82','name' => 'LR PATRIOTS','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '81','name' => 'Team Alpha','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '83','name' => 'Luzon Leaders','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '84','name' => 'Team Rainmakers','leader_id' => NULL,'status' => 'resigned','logo' => NULL),
  array('id' => '85','name' => 'Team Champions','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '86','name' => 'FH Solid North','leader_id' => NULL,'status' => 'inactive','logo' => NULL),
  array('id' => '87','name' => 'Team FH Luzon - Ledesma','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '88','name' => 'FILIPINOHOMES KAIZEN OZAMIZ TEAM','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '89','name' => 'The Prodigies','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '91','name' => 'Team Rich','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '92','name' => 'Chin Dynasty','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/chin-dynasty.jpg'),
  array('id' => '93','name' => 'Filipino Homes New Force','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '94','name' => 'FH Racstars','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/fh-racstars.png'),
  array('id' => '95','name' => 'Filipino Homes Team 8','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '96','name' => 'G-Force','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '97','name' => 'FH Win Team','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '98','name' => 'LR Rising Star','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/lr-rising-star.jpg'),
  array('id' => '99','name' => 'Team Grateful','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/team-grateful.png'),
  array('id' => '100','name' => 'EMPower','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '101','name' => 'Genieus','leader_id' => NULL,'status' => 'active','logo' => NULL),
  array('id' => '102','name' => 'LR Premier','leader_id' => NULL,'status' => 'active','logo' => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/lrwebsite/teams/lr-premier.jpg')

        ];

        foreach (array_chunk($teams, 100) as $chunk) {
            DB::table('teams')->insert($chunk);
        }
    }
}
