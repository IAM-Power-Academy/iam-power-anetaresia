# IAM Power Anëtarësia

Plugin WordPress për aktivizimin e anëtarëve të IAM Power Academy në kurset LearnDash.

**Funksionet:** aktivizim manual dhe në grup, paketa me afat, kode aktivizimi (`[iam_aktivizo]`), faqja e anëtarit (`[iam_kurset_e_mia]`), webhook për Digistore24 / Ko-fi / Systeme.io, skadim automatik me email, regjistër veprimesh, mjete sigurie (ChatGPT/OpenAI) dhe përditësim automatik nga ky repo.

## Si publikohet një version i ri

1. Ndrysho `Version:` dhe `IAMP_VERSION` te `iam-power-anetaresia.php` (p.sh. `1.2.0`).
2. Bëj commit dhe shtyj etiketën `v1.2.0`.
3. GitHub Actions ndërton ZIP-in dhe krijon Release-in.
4. WordPress-i në iampower.al e sheh versionin e ri brenda 6 orëve (ose menjëherë me “Kontrollo tani” te *IAM Anëtarët → Cilësimet & Email*).

Udhëzimi i plotë në shqip: `LEXO-MUA.txt`.
