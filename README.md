# hookdata

Webhook receiver สำหรับเก็บเหตุการณ์ `register` / `deposit` / `withdraw` ลง MySQL
พร้อมหน้าเว็บสำหรับดูข้อมูล (PHP + mysqli).

A small PHP + MySQL webhook receiver that ingests `register` / `deposit` / `withdraw`
events and a web page to browse the stored records.

---

## สิ่งที่มีในโปรเจกต์ / Files

| File | หน้าที่ / Purpose |
|------|-------------------|
| `hook.php` | รับ webhook (POST) และบันทึกลงฐานข้อมูล / webhook endpoint |
| `view.php` | หน้าเว็บดูข้อมูล (ต้องล็อกอิน) / viewer UI (auth required) |
| `dbconnect.php` | เชื่อมต่อฐานข้อมูล อ่านค่าจาก config / DB connection |
| `app_config.php` | ตัวโหลด config (env / .env / config.php) / config loader |
| `lib.php` | ฟังก์ชันร่วม: auth, validation, decrypt / shared helpers |
| `schema.sql` | โครงสร้างตาราง 3 ตาราง / table definitions |
| `.env.example`, `config.sample.php` | ตัวอย่างค่า config / config templates |

---

## การติดตั้ง / Setup

1. **สร้างฐานข้อมูลและตาราง / Create DB and tables**
   ```bash
   mysql -u root -p < schema.sql
   ```

2. **ตั้งค่า config** — เลือกวิธีใดวิธีหนึ่ง (หรือใช้ร่วมกัน):
   - คัดลอก `.env.example` → `.env` แล้วกรอกค่า, **หรือ**
   - คัดลอก `config.sample.php` → `config.php` แล้วกรอกค่า
   - ตั้งเป็น environment variable จริงบนเว็บเซิร์ฟเวอร์

   ลำดับความสำคัญ (สูง→ต่ำ): **env var → .env → config.php → ค่า default**

3. **สร้างรหัสผ่านสำหรับหน้า view.php / Generate viewer password hash**
   ```bash
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   นำค่าที่ได้ไปใส่ใน `VIEWER_PASS_HASH`.

4. **รัน / Run** (เช่น local dev)
   ```bash
   php -S 127.0.0.1:8000
   ```

> ⚠️ `.env` และ `config.php` ถูก git-ignore แล้ว — **อย่า commit ค่าจริง**

---

## ค่า Config / Environment variables

| ตัวแปร / Variable | คำอธิบาย / Description |
|-------------------|------------------------|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | การเชื่อมต่อฐานข้อมูล / database connection |
| `DECRYPT_API_URL` | URL ของบริการถอดรหัส ต้องมี `{data}` หรือลงท้ายด้วย `/` |
| `DECRYPT_TIMEOUT` | timeout (วินาที) ของการเรียก decrypt, ค่าเริ่มต้น 5 |
| `DECRYPT_PASSTHROUGH` | `true` = ข้ามการถอดรหัส ใช้ค่าดิบ (สำหรับทดสอบเท่านั้น) |
| `WEBHOOK_SECRET` | secret สำหรับยืนยัน webhook (ว่าง = ปิด auth, ไม่แนะนำ) |
| `VIEWER_USER`, `VIEWER_PASS_HASH` | user/password hash สำหรับหน้า view.php |

---

## Endpoints

ทุก endpoint รับเฉพาะ **POST** และ body เป็น JSON รูปแบบ `{ "data": { ... } }`
ตอบกลับเป็น JSON พร้อม HTTP status code ที่ถูกต้องเสมอ.

| Method | URL | ใช้ทำอะไร |
|--------|-----|-----------|
| POST | `/hook.php?register` | บันทึกการสมัคร |
| POST | `/hook.php?deposit`  | บันทึกการฝาก |
| POST | `/hook.php?withdraw` | บันทึกการถอน |

### Authentication ของ webhook
ส่งอย่างใดอย่างหนึ่ง (เมื่อ `WEBHOOK_SECRET` ถูกตั้งค่า):
- Header `X-Webhook-Secret: <secret>` — เทียบแบบ constant-time, **หรือ**
- Header `X-Signature: sha256=<hex>` — HMAC-SHA256 ของ raw body ด้วย key = secret

### ตัวอย่าง payload / Payload examples

**register**
```json
{ "data": {
  "username": "<encrypted>", "tel": "<encrypted>", "accountNumber": "<encrypted>",
  "firstName": "Alice", "lastName": "A", "bankName": "SCB",
  "prefix": "demo", "createDate": "2026-10-06 10:00:00", "bonus": "0"
}}
```
**deposit** (`value`, `bonus` ต้องเป็นตัวเลข)
```json
{ "data": {
  "username": "<encrypted>", "bankName": "SCB", "bankNo": "111",
  "dateBank": "2026-10-06", "detail": "d", "value": 500, "bonus": 50,
  "topUp": "0", "prefix": "demo", "createDate": "2026-10-06",
  "updateDate": "2026-10-06", "actionName": "a"
}}
```
**withdraw** (`value`, `beforeValue` ต้องเป็นตัวเลข)
```json
{ "data": {
  "username": "<encrypted>", "accountNumber": "<encrypted>", "tel": "<encrypted>",
  "bankName": "SCB", "name": "Alice", "value": 100, "beforeValue": 600,
  "afterValue": "500", "type": "auto", "prefix": "demo",
  "createDate": "2026-10-06 11:00:00", "updateDate": "2026-10-06", "actionName": "a"
}}
```

ฟิลด์ `username`, `tel`, `accountNumber` จะถูกส่งไปถอดรหัสผ่าน `DECRYPT_API_URL`
(บริการต้องตอบ JSON `{"decrypted":"...:<value>"}`).

### ตัวอย่างการเรียก / Example request
```bash
curl -X POST "http://localhost:8000/hook.php?deposit" \
  -H "X-Webhook-Secret: $WEBHOOK_SECRET" \
  -d '{"data":{"username":"ENC","bankName":"SCB","bankNo":"111","dateBank":"2026-10-06","detail":"d","value":500,"bonus":50,"topUp":"0","prefix":"demo","createDate":"2026-10-06","updateDate":"2026-10-06","actionName":"a"}}'
```

### รหัสสถานะที่ตอบกลับ / Status codes
- `201` บันทึกสำเร็จ · `200` ข้อมูลซ้ำ (duplicate)
- `400` body ว่าง/JSON ผิด · `401` auth ไม่ผ่าน · `404` endpoint ไม่ถูกต้อง
- `405` ไม่ใช่ POST · `422` ข้อมูลไม่ครบ/ชนิดผิด/ถอดรหัสไม่สำเร็จ · `500` ข้อผิดพลาดภายใน

---

## หน้า view.php
เปิดในเบราว์เซอร์แล้วกรอก user/password (HTTP Basic auth จาก `VIEWER_USER` / `VIEWER_PASS_HASH`).
- กรองตามประเภท (register/deposit/withdraw) และ username
- แบ่งหน้า (pagination) ครั้งละ 30 แถว
- ค่าในตารางผ่าน `htmlspecialchars` ป้องกัน XSS

---

## หมายเหตุด้านความปลอดภัย / Security notes
- **อย่าเปิด view.php โดยไม่ตั้ง auth** — ข้อมูลมี PII (ชื่อ, เบอร์, เลขบัญชี)
- **อย่า commit `.env` / `config.php`** ที่มีค่าจริง (ถูก gitignore แล้ว)
- ตั้ง `WEBHOOK_SECRET` เสมอใน production
- ใช้ prepared statements ทุกคำสั่ง SQL และเปิด mysqli exception mode
- ข้อผิดพลาดภายในจะถูกเขียนลง error log ไม่ส่งรายละเอียดออกไปหา client
