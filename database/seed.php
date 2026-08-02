<?php
/**
 * Seeder — creates demo accounts and sample data.
 * Run once:  php database/seed.php
 */
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/auth.php';

db(); // triggers SQLite bootstrap if fresh

if ((int) scalar('SELECT COUNT(*) FROM users') > 0) {
    echo "Database already seeded. Delete storage/autoshop.sqlite to reseed.\n";
    exit;
}

function seed_user($name, $username, $pass, $role, $spec = null) {
    insert('users', ['name'=>$name,'username'=>$username,'email'=>$username.'@autoshop.test',
        'password_hash'=>hash_password($pass),'role'=>$role,'specialization'=>$spec,'active'=>1]);
}
seed_user('System Administrator','admin','Admin@123','admin');
seed_user('Ama Owusu','manager','Manager@123','manager');
seed_user('Efua Baidoo','receptionist','Reception@123','receptionist');
seed_user('Kofi Boateng','mechanic','Mechanic@123','mechanic','Engine & transmission');
seed_user('Yaw Adjei','mechanic2','Mechanic@123','mechanic','Brakes & suspension');

// services
$services = [['Engine oil change',120],['Brake pad replacement',180],['Full diagnostics',90],
    ['Wheel alignment',150],['AC servicing',250],['Battery replacement',80]];
foreach ($services as [$n,$c]) insert('services',['name'=>$n,'labour_charge'=>$c,'active'=>1]);

// spare parts
$parts = [['Engine oil 5W-30 (4L)','OIL-530',24,6,180],['Brake pad set','BRK-001',8,5,140],
    ['Oil filter','FLT-OIL',30,10,35],['Air filter','FLT-AIR',15,8,55],
    ['Car battery 12V','BAT-12V',4,3,520],['Spark plug','SPK-01',40,12,28],
    ['Wiper blade','WPR-01',3,6,45]]; // last one already low-stock for demo
foreach ($parts as [$n,$s,$q,$r,$p]) insert('spare_parts',['name'=>$n,'sku'=>$s,'quantity'=>$q,'reorder_level'=>$r,'unit_price'=>$p]);

// customers + vehicles
$cust = [
  ['Kwame','Mensah','024 555 1001','kwame@example.com','East Legon, Accra'],
  ['Akosua','Darko','020 555 1002','akosua@example.com','Kumasi'],
  ['Yaw','Asante','054 555 1003','yaw@example.com','Tema'],
];
$cids = [];
foreach ($cust as [$fn,$ln,$p,$e,$a]) $cids[] = insert('customers',['first_name'=>$fn,'last_name'=>$ln,'name'=>trim("$fn $ln"),'phone'=>$p,'email'=>$e,'address'=>$a]);

$veh = [
  [$cids[0],'GR-1234-24','Toyota','Corolla',2019,'Silver'],
  [$cids[0],'GT-9981-22','Hyundai','Elantra',2021,'Black'],
  [$cids[1],'AS-4567-20','Honda','Civic',2018,'Blue'],
  [$cids[2],'GE-7788-23','Kia','Sportage',2022,'White'],
];
$vids = [];
foreach ($veh as [$c,$r,$mk,$md,$y,$col]) $vids[] = insert('vehicles',['customer_id'=>$c,'reg_number'=>$r,'make'=>$mk,'model'=>$md,'year'=>$y,'color'=>$col]);

// a couple of job cards (mechanic ids: 4 = Kofi Boateng, 5 = Yaw Adjei)
$j1 = insert('job_cards',['vehicle_id'=>$vids[0],'mechanic_id'=>4,'created_by'=>2,'fault_desc'=>'Engine noise and due for service.','estimate'=>300,'status'=>'in_progress','estimated_completion'=>date('Y-m-d', strtotime('+2 days'))]);
insert('job_faults',['job_card_id'=>$j1,'fault_desc'=>'Engine noise and due for service.','mechanic_id'=>4]);
insert('job_services',['job_card_id'=>$j1,'service_id'=>1,'description'=>'Engine oil change','charge'=>120]);
insert('job_parts',['job_card_id'=>$j1,'spare_part_id'=>1,'quantity'=>1,'unit_price'=>180]);
q('UPDATE spare_parts SET quantity = quantity - 1 WHERE id = 1');

$j2 = insert('job_cards',['vehicle_id'=>$vids[2],'mechanic_id'=>5,'created_by'=>2,'fault_desc'=>'Brakes squeaking.','estimate'=>320,'status'=>'open','estimated_completion'=>date('Y-m-d', strtotime('+3 days'))]);
insert('job_faults',['job_card_id'=>$j2,'fault_desc'=>'Brakes squeaking.','mechanic_id'=>5]);
insert('job_faults',['job_card_id'=>$j2,'fault_desc'=>'AC not cooling properly.','mechanic_id'=>4]);

// appointment
insert('appointments',['customer_id'=>$cids[1],'vehicle_id'=>$vids[2],'scheduled_at'=>date('Y-m-d H:i:s', strtotime('+1 day 10:00')),'note'=>'Brake inspection','status'=>'scheduled']);

echo "Seeded successfully.\n\nLogin accounts:\n";
echo "  Admin        : admin / Admin@123\n";
echo "  Manager      : manager / Manager@123\n";
echo "  Receptionist : receptionist / Reception@123\n";
echo "  Mechanic     : mechanic / Mechanic@123 (Engine & transmission)\n";
echo "  Mechanic 2   : mechanic2 / Mechanic@123 (Brakes & suspension)\n";
