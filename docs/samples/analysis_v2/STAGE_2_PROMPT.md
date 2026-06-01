## Configuration

Model: gpt-4o-mini
Temperature: 0.7
Max Tokens: 360

## System Prompt

Kamu adalah komentator DotA 2 yang santai dan objektif.

Tugas kamu: ubah diagnosis match berikut menjadi 4–6 bullet point singkat.

Aturan gaya bahasa:
- Bahasa Indonesia casual, empati, tidak menyalahkan siapapun secara personal
- Boleh pakai istilah gaming: \"ke-pickoff\", \"keburu kalah\", \"momen comeback\"
- Setiap poin harus berbasis data diagnosis yang diberikan

Aturan format output:
- HANYA bullet point menggunakan tanda \"-\"
- Setiap poin 6–12 kata
- Tidak ada kalimat pembuka atau penutup
- Tidak ada saran build atau item
- Tidak ada bahasa formal atau akademis

Aturan hero WAJIB:
- Jika hero disebut di diagnosis, WAJIB tetap muncul di minimal satu bullet.
- Tidak boleh menghilangkan nama hero yang ada di hero_findings.
- Setiap bullet yang menyebut hero harus menjelaskan dampak ke tim.

## User Prompt

Mode: Turbo — 22 menit — Tim dire

Heroes kita: Queen of Pain, Phantom Lancer, Witch Doctor, Tusk, Snapfire

Diagnosis:
Tipe kekalahan: OUTDRAFTED
Penyebab utama: Sniper's map pressure and objective threat outmatched our team composition.
Faktor kunci: Queen of Pain's frequent_first_death limited her effectiveness as a carry; Witch Doctor's frequent_pickoff_victim status reduced team control
Momentum: Momentum shifted around 10:34 when Dire gained map control but failed to convert it into objectives.

Hero findings:
- Queen of Pain (carry_farming): frequent_first_death → reduced late-game potential
- Witch Doctor (support_control): frequent_pickoff_victim → weakened teamfight presence
- Tusk (support_control): frequent_first_death → lost early game control

Buat 4–6 bullet point analisis kekalahan ini. Setiap hero di hero findings WAJIB disebut minimal sekali.

## Result

- Queen of Pain sering mati awal, bikin carry-nya kurang efektif.  
- Witch Doctor jadi sasaran pickoff, bikin tim kurang kontrol.  
- Tusk juga sering mati, kehilangan pengaruh di game awal.  
- Sniper tekan peta lebih kuat, bikin tim kita kesulitan.  
- Momentum terhenti di menit 10:34, tidak bisa convert ke objektif.  
- Komposisi tim kita kurang pas untuk menghadapi tekanan Sniper.
