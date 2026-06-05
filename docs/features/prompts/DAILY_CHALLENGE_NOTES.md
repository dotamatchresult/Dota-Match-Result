# Catatan di Tengah Implementasi

Berikut adalah ide atau penyesuaian yang dapat dilakukan setelah implementasi awal sudah selesai.

### 1. Jumlah member `whatsapp` dan `telegram` berbeda.

- Member `whatsapp` berisi 3 orang
- Member `telegram` berisi 12 orang
- Initial value pada challenge perlu disesuaikan dengan jumlah member
- Misal untuk `whatsapp` diberikan challenge "Dapatkan total 30000 damage" (team based, can be finished in multiple matches). Unfinished: +1000 damage. Maximum: 35000 damage.
- Sedangkan untuk `telegram` initial value di challange yang sama ditambahkan menjadi "Dapatkan total 45000 damage". Unfinished: +2000 damage. Maximum: 55000 damage.
- Dengan begitu, untuk Destination `whatsapp` dapat menyelesaikan dengan 3 party per match. Dan Destination `telegram` dapat menyelesaikan dengan 5 party.
