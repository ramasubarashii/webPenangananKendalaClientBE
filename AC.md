# Acceptance Criteria

Dokumen ini merangkum Acceptance Criteria yang sesuai dengan cakupan pengujian dari unit testing hingga integration testing pada sistem ticketing dan autentikasi backend.

## 1. Scope

Acceptance Criteria ini mencakup:
- Autentikasi dan profil pengguna
- Role-based access control (RBAC)
- Manajemen tiket
- Workflow claim tiket
- Relasi model Ticket, ProgressLog, dan User
- Validasi keamanan serta log internal

---

## 2. Acceptance Criteria untuk Unit Testing

### AC-UT-01: Ticket terhubung ke creator yang benar
- Given sebuah user client/programmer yang dibuat di database
- When tiket dibuat dengan user_id yang mengacu ke user tersebut
- Then sistem harus menampilkan relasi creator dari ticket tersebut dan mengembalikan data user yang sama
- Acceptance: `ticket->creator` bernilai instance dari `App\Models\User` dan `id` sesuai dengan user pengirim tiket

### AC-UT-02: Ticket memiliki banyak progress log
- Given sebuah ticket yang sudah dibuat
- When progress log ditambahkan ke ticket tersebut
- Then sistem harus menyimpan catatan log dan mengaitkannya dengan ticket yang benar
- Acceptance: jumlah `progressLogs` sesuai, dan `notes` dari log pertama sesuai dengan data yang dikirim

---

## 3. Acceptance Criteria untuk Integration Testing

### AC-INT-01: Login berhasil untuk user aktif
- Given user dengan email dan password valid serta status aktif
- When request `POST /api/login` dikirim dengan payload email dan password benar
- Then respons harus sukses dengan status 200
- Acceptance:
  - Mengembalikan `access_token`
  - Mengembalikan `token_type`
  - Mengembalikan data user minimal `id`, `name`, `email`, dan `role`

### AC-INT-02: Login gagal saat kredensial tidak valid
- Given user terdaftar dengan password tertentu
- When password yang dikirim tidak sesuai
- Then sistem menolak login
- Acceptance:
  - Status respons 422
  - Error validasi ditampilkan pada field `email`

### AC-INT-03: User non-aktif tidak dapat login
- Given user terdaftar tetapi `is_active = false`
- When request login dilakukan dengan password benar
- Then sistem menolak autentikasi
- Acceptance:
  - Status respons 422
  - Validasi error muncul pada field `email`

### AC-INT-04: User terautentikasi dapat melihat profil
- Given user sudah login
- When request `GET /api/me` dipanggil
- Then sistem mengembalikan data profil pengguna yang login
- Acceptance:
  - Status 200
  - `email` dan `role` sesuai dengan user yang login

### AC-INT-05: User terautentikasi dapat memperbarui profil
- Given user sudah login
- When request `PUT /api/profile` dikirim dengan nama/email baru dan password saat ini valid
- Then data profil pengguna diperbarui di database
- Acceptance:
  - Status 200
  - Response menampilkan `user.name` baru
  - Record pada tabel `users` berubah sesuai perubahan

### AC-INT-06: User terautentikasi dapat mengganti password
- Given user sudah login
- When request `PUT /api/profile/password` dikirim dengan password lama valid dan password baru yang valid
- Then password lama diganti dengan password baru
- Acceptance:
  - Status 200
  - Password baru dapat diverifikasi dengan `Hash::check()`

### AC-INT-07: Non-admin tidak bisa mengakses route admin
- Given user yang memiliki role selain `admin`
- When request ke `/api/admin/users` dijalankan
- Then sistem menolak akses
- Acceptance: status 403

### AC-INT-08: Admin dapat melihat daftar user
- Given user login dengan role `admin`
- When request `GET /api/admin/users` dipanggil
- Then sistem mengembalikan daftar pengguna
- Acceptance:
  - Status 200
  - Respons berisi data users sesuai hasil query

### AC-INT-09: Admin dapat membuat user baru
- Given admin sudah login
- When request `POST /api/admin/users` dikirim dengan data user baru
- Then user baru berhasil dibuat
- Acceptance:
  - Status 201
  - Record baru ada di tabel `users`
  - Log aktivitas admin dibuat di tabel `admin_activity_logs` dengan `action = create_user`

### AC-INT-10: Admin dapat menonaktifkan user
- Given admin login dan user target aktif
- When request `PATCH /api/admin/users/{id}/toggle-active` dipanggil
- Then status aktif user target berubah menjadi non-aktif
- Acceptance:
  - Status 200
  - `is_active` berubah menjadi false
  - `admin_activity_logs` mencatat action `deactivate_user`

### AC-INT-11: Admin dapat reset password user lain
- Given admin login dan user target valid
- When request `POST /api/admin/users/{id}/reset-password` dikirim dengan password baru
- Then password target berubah sesuai nilai baru
- Acceptance:
  - Status 200
  - Hash password baru valid dan sesuai

### AC-INT-12: User belum login tidak bisa mengakses daftar tiket
- Given user belum autentikasi
- When request `GET /api/tickets` dijalankan
- Then sistem menolak akses
- Acceptance: status 401

### AC-INT-13: Service Desk dapat membuat tiket, programmer tidak dapat
- Given role `service_desk` dan `programmer`
- When service desk membuat tiket dengan valid data
- Then tiket berhasil dibuat dengan `ticket_id` format `TCK-######-####`
- Acceptance:
  - Status 201
  - Data tersimpan di tabel `tickets`
  - Format `ticket_id` sesuai pattern
  - Programmer yang mencoba membuat tiket mendapat status 403

### AC-INT-14: PM dapat melakukan assignment tiket, user lain tidak dapat
- Given tiket terbuka dan user dengan role `project_manager`
- When PM mengirim request `POST /api/tickets/{ticket_id}/assign`
- Then tiket berhasil di-assign ke programmer tertentu
- Acceptance:
  - Status 200 untuk PM
  - Data tersimpan di tabel `ticket_assignments`
  - `estimated_hours` sesuai payload
  - Service Desk atau role lain yang mencoba assignment mendapat 403

### AC-INT-15: Programmer hanya dapat mengubah status tiket yang telah di-assign ke dirinya
- Given tiket dimiliki oleh programmer tertentu
- When programmer lain mencoba mengubah status tiket
- Then akses ditolak
- Acceptance:
  - Status 403 untuk programmer lain
  - Programmer yang ditugaskan dapat berhasil mengubah status dan data `tickets.status` berubah sesuai

### AC-INT-16: Client dapat membuat serta melihat tiket miliknya sendiri
- Given user login dengan role `client`
- When client membuat tiket melalui `/api/client/tickets`
- Then tiket masuk ke data milik client tersebut
- Acceptance:
  - Status 201
  - `user_id` sesuai user login
  - Client dapat melihat daftar ticket miliknya di endpoint client
  - Status 200 untuk list dan detail tiket
  - User lain tidak dapat melihat detail tiket client milik orang lain, status 403

### AC-INT-17: Service Desk dapat eskalasi tiket ke PM dan sistem menjaga visibilitas per role
- Given tiket masih dalam status `open`
- When service desk melakukan eskalasi dengan payload valid
- Then status tiket berubah menjadi `escalated_to_pm` dan data tambahan seperti `internal_notes`, `assigned_to_role`, dan `category` tersimpan
- Acceptance:
  - Status 200
  - Data di tabel `tickets` berubah sesuai
  - PM dan owner melihat tiket yang di-eskalasi sesuai role
  - Service Desk dapat melihat both `open` dan `escalated_to_pm`

### AC-INT-18: Log internal tidak terlihat oleh client, tetapi terlihat oleh staf internal
- Given tiket memiliki dua log: satu internal dan satu public
- When client memanggil endpoint tiket client
- Then hanya log publik yang ditampilkan
- Acceptance:
  - `progress_logs` client berisi 1 item
  - notes log public sesuai
  - Staff internal dapat melihat kedua log (internal dan public)

### AC-INT-19: Tiket yang sudah ditutup atau ditolak tidak dapat menambahkan log
- Given tiket dengan status `closed` atau `rejected`
- When request untuk menambahkan log dikirim
- Then sistem menolak operasi
- Acceptance:
  - Status 422
  - Message respon: `Tidak dapat menambahkan catatan atau pesan pada tiket yang sudah ditutup atau ditolak.`

### AC-INT-20: PM dapat melakukan review status tiket
- Given ticket dalam status `pending_review`
- When PM mengirim keputusan `not_ok` atau `ok`
- Then sistem mengubah status sesuai keputusan
- Acceptance:
  - `not_ok` => status berubah menjadi `in_progress`
  - `ok` => status berubah menjadi `resolved`
  - Status 200 pada setiap keputusan

### AC-INT-21: PM dapat eskalasi ke owner dan owner dapat mengambil keputusan
- Given tiket berstatus `escalated_to_pm`
- When PM mengirim eskalasi ke owner
- Then tiket berubah ke status `escalated_to_owner`
- Acceptance:
  - Status respons 200
  - Owner kemudian dapat memutuskan `approved` dan status tiket dipulangkan ke `escalated_to_pm`

### AC-INT-22: Workflow claim tiket berfungsi sesuai peran
- Given tiket dalam status `escalated_to_pm` dan PM me-release ke daftar claim
- When programmer melihat `/api/tickets/available` dan melakukan claim
- Then tiket berpindah ke status `waiting_pm_approval` dan `claimed_programmer_id` terisi
- Acceptance:
  - PM dapat me-release ticket untuk claim
  - Programmer dapat melihat ticket yang tersedia
  - PM dapat approve claim dan status berubah menjadi `assigned`
  - `ticket_assignments` dibuat dengan `ticket_id`, `programmer_id`, dan `pm_id`

---

## 4. Non-Functional Acceptance Criteria

### AC-NF-01: Keamanan akses API
- Setiap endpoint harus memvalidasi autentikasi dan otorisasi sesuai role.
- User tidak boleh melihat atau mengubah data yang bukan miliknya.

### AC-NF-02: Validasi input
- Semua request yang memasukkan data wajib lolos validasi backend.
- Input tidak valid harus menghasilkan respons error yang jelas.

### AC-NF-03: Integritas data
- Relasi `user_id`, `ticket_id`, `programmer_id`, dan `pm_id` harus konsisten dengan data yang tersimpan di database.
- Perubahan status dan assignment harus tercatat pada tabel terkait.

### AC-NF-04: Audit trail
- Aktivitas administratif seperti create/reset/deactivate user harus tercatat pada `admin_activity_logs`.
- Progress log harus dapat dibedakan antara log internal dan log publik.

---

## 5. Exit Criteria

Sistem dianggap menerima (accepted) apabila seluruh acceptance criteria di atas terpenuhi dan semua test yang mencakup unit hingga integration test berhasil dijalankan tanpa error yang berkaitan dengan fungsi bisnis utama.
