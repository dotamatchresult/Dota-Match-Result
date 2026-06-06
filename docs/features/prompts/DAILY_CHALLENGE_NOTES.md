# Catatan di Tengah Implementasi

Berikut adalah ide atau penyesuaian yang dapat dilakukan setelah implementasi awal sudah selesai.

---

### 1. Jumlah member `whatsapp` dan `telegram` berbeda.

- Member `whatsapp` berisi 3 orang
- Member `telegram` berisi 12 orang
- Initial value pada challenge perlu disesuaikan dengan jumlah member
- Misal untuk `whatsapp` diberikan challenge "Dapatkan total 30000 damage" (team based, can be finished in multiple matches). Unfinished: +1000 damage. Maximum: 35000 damage.
- Sedangkan untuk `telegram` initial value di challange yang sama ditambahkan menjadi "Dapatkan total 45000 damage". Unfinished: +2000 damage. Maximum: 55000 damage.
- Dengan begitu, untuk Destination `whatsapp` dapat menyelesaikan dengan 3 party per match. Dan Destination `telegram` dapat menyelesaikan dengan 5 party.

---

### 2. Win with random Hero

- Meepo is excluded from the randomized pool.
- For this condition, let's add one unique challenge of "Win a game with Meepo" but upon challenge unfinished we don't increment the challenge. So `base_requirement`: 1 win, `increment_value`: +0 win, `max_requirement`: 1 win.

---

### 3. Challenge `level` belum ada

- Kolom `level sudah tersimpan namun challenge belum ter-assign
- Contoh challenge "Win a game by reaching level 30". Unfinished: +0 win. Max: 1 win.

---

### 4. Should challenges have difficulty tiers?

Example:

```
easy
medium
hard
```

Then assignment could later evolve into:

```
Monday-Thursday → easy/medium
Friday-Sunday → medium/hard
```

Not needed immediately, but much easier to add now.

My recommendation is `YES`. Store it in the catalog.

