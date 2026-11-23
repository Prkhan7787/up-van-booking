<?php
declare(strict_types=1);
namespace App\Modules\Booking;

/** Sample references, never a national standard or a measurement of the selected van. */
final class FuelEconomyReferenceCatalog
{
    public const SOURCE_URL='https://www.otp.go.th/uploads/tiny_uploads/PDF/2567-01/01/1_Final%20Report.pdf';
    public const REFERENCES=[
        'gasoline'=>['group'=>'Gasoline','samples'=>[7.41,7.69,7.46],'usable'=>false,
            'note'=>'พักการใช้กลุ่ม Gasoline: ตาราง 4.2.6-1 ระบุรถกลุ่มเดียวกันเป็น NGV และหน่วย km/kg ต่างจากตาราง 4.2.4-3 จึงต้องยืนยันชนิดเชื้อเพลิงก่อน'],
        'gasohol'=>['group'=>'Gasohol 95/91','samples'=>[7.95,9.76,9.19],'usable'=>true,
            'note'=>'จับคู่ตามกลุ่มแก๊สโซฮอล์ 91/95 การศึกษาไม่ได้ทดสอบผลิตภัณฑ์ S EVO โดยเฉพาะ ค่าในตารางสรุป 4.2.6-1 ต่างเล็กน้อย จึงใช้เฉพาะชุดตาราง 4.2.4-3 อย่างสอดคล้องกัน'],
        'diesel_b7'=>['group'=>'Diesel B7','samples'=>[13.65,14.03,13.28],'usable'=>true,
            'note'=>'ใช้เฉพาะชนิดที่ยืนยันว่าเป็นดีเซล B7 ไม่อนุมานจากชื่อผลิตภัณฑ์ดีเซลทั่วไป'],
    ];
    public static function forFuelType(string $fuelType): ?array
    {
        $key=match($fuelType){
            'แก๊สโซฮอล์ 91 S EVO','แก๊สโซฮอล์ 95 S EVO'=>'gasohol',
            'เบนซิน','เบนซิน 95'=>'gasoline',
            'ดีเซล B7'=>'diesel_b7',
            default=>null,
        };
        if($key===null)return null;
        $ref=self::REFERENCES[$key]; $samples=$ref['samples'];
        return $ref+['id'=>'otp-2566-table-4.2.4-3-'.$key,'fuel_type'=>$fuelType,
            'mean'=>array_sum($samples)/count($samples),'min'=>min($samples),'max'=>max($samples),
            'sample_count'=>count($samples),'unit'=>'กม./ลิตร','agency'=>'สำนักงานนโยบายและแผนการขนส่งและจราจร (สนข.)',
            'year'=>2566,'report'=>'การศึกษานโยบายลดการใช้พลังงานในภาคคมนาคมขนส่งทางบก ตุลาคม 2566',
            'source_url'=>self::SOURCE_URL,'table'=>'4.2.4-3','pages'=>'4-49–4-50','pdf_page'=>232,
            'method'=>'ค่าเฉลี่ยเลขคณิตของค่ารายคัน 3 คันในกลุ่มเดียวกัน แต่ละค่ารายคันเฉลี่ยความเร็ว 3 รูปแบบ ไม่ถ่วงน้ำหนักตามสัดส่วนรถในประเทศ',
            'limitations'=>'กลุ่มตัวอย่างรถตู้ รย.2 รวม 9 คัน ทดสอบที่กรมควบคุมมลพิษ ธัญบุรี ด้วย Chassis Dynamometer วัฏจักร BKK345H (Hot Emission) ความเร็วต่ำ/กลาง/สูง อ้างอิง มอก.2540-2554 กลุ่ม Gasohol 2,362–2,700 ซีซี อายุ 2–7, 8–15, 16–20 ปี ช่วงค่าคือค่าต่ำสุด–สูงสุดของตัวอย่าง ไม่ใช่ช่วงความเชื่อมั่นหรือขอบเขตค่าใช้จ่ายจริง'];
    }
}
