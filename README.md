# Lexible — backend

Telegram Mini App uchun API, bot va admin panel. Laravel 13 + Filament 5.

Frontend alohida repoda: [Lexible_front](https://github.com/taqseemuz)

## Ishga tushirish

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan lexible:admin --name="Admin" --email="admin@example.com"
php artisan serve --port=8123
```

## Arxitektura

**Autentifikatsiya.** O'yinchilar uchun login yo'q. Telegram Mini App yuboradigan
`initData` imzosi har API so'rovida HMAC-SHA256 bilan qayta tekshiriladi
(`AuthenticateMiniApp` middleware). Admin panel esa alohida `admins` jadvali va
`admin` guard bilan ishlaydi — ikki tizim hech qayerda kesishmaydi.

**Lug'at.** So'z avval `words` jadvalidan qidiriladi; topilmasa bepul lug'at
API'sidan olinadi, tozalanadi, saqlanadi va shundan keyin doim bazadan
beriladi (`DictionaryService`).

**O'zlashtirish.** Har so'z olti o'lchov bo'yicha alohida baholanadi —
`card`, `uz2en`, `en2uz`, `spell`, `image`, `match`. O'rtachasi so'zning umumiy
foizi bo'ladi (`word_progress` jadvali).

**Savollar.** Test savollari serverda tuziladi va to'g'ri javob mijozga
yuborilmaydi. Har test turi o'z maydonlarini yashiradi: "inglizchasi nima?"
savolida so'zning o'zi ham, audiosi ham berilmaydi (`TestBuilder::forClient`).

## Buyruqlar

```bash
php artisan telegram:setup            # webhook, bot buyruqlari, menyu tugmasi
php artisan telegram:setup --info     # webhook holati
php artisan dictionary:seed --limit=600
php artisan dictionary:translate --limit=250
php artisan dictionary:emoji
php artisan lexible:admin
```

## Testlar

```bash
php artisan test
```

`MiniAppFlowTest` butun o'yin oqimini imzolangan `initData` bilan tekshiradi:
onboarding → xarita → kategoriya → so'z qo'shish → test → yakun.
