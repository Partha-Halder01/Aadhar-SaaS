# ডিপ্লয়মেন্ট গাইড (বাংলা) — Utkal Print Portal

> **সাইট:** https://onlinedigitalservice.xyz
> **হোস্টিং:** GoDaddy cPanel (Linux, Apache, PHP 8.3)
> **রিপো:** https://github.com/Partha-Halder01/Aadhar-SaaS
> **সর্বশেষ আপডেট:** ১৬ সেপ্টেম্বর ২০২৬

এই গাইডটা বাস্তবে করা ডিপ্লয়মেন্ট থেকে লেখা — যে সমস্যাগুলো সত্যিই হয়েছিল, সেগুলোর সমাধানসহ।

---

## ১. সার্ভারের গঠন (আগে এটা বুঝে নিন)

সার্ভারে তিনটে আলাদা জায়গা আছে। কোনটা কী কাজ করে, সেটা না জানলে ডিপ্লয় ভাঙবে।

| জায়গা | কী আছে | কাজ |
|---|---|---|
| `~/app` | GitHub রিপোর পুরো কপি | সোর্স কোড। এখানেই `git pull` হয় |
| `~/app/backend` | Laravel অ্যাপ | `.env`, `vendor/`, `artisan` — ওয়েব থেকে সরাসরি পৌঁছানো যায় না (নিরাপদ) |
| `~/public_html` | ওয়েব রুট | ব্রাউজার যা দেখে। প্রতি ডিপ্লয়ে এটা **মুছে নতুন করে বানানো হয়** |

**গুরুত্বপূর্ণ কথা:** `~/public_html` একটা *তৈরি হওয়া* ফোল্ডার — এখানে হাতে কিছু এডিট করবেন না। যা এডিট করবেন সব হারিয়ে যাবে পরের ডিপ্লয়ে। সবসময় লোকাল কোড → GitHub → সার্ভার, এই পথে যান।

**দরকারি তথ্য:**

```
cPanel ইউজার     : lb4jd7g16jmr
হোম ডিরেক্টরি     : /home/lb4jd7g16jmr
MySQL ডাটাবেস    : lb4jd7g16jmr_aadhar
MySQL ইউজার      : lb4jd7g16jmr_aadhar
DB পাসওয়ার্ড      : ~/.dbpass ফাইলে রাখা আছে (chmod 600)
পুরনো WordPress  : ~/old-wp-backup (৩৩৭ MB — নিশ্চিত হলে মুছে ফেলতে পারেন)
```

---

## ২. পুরো ডিপ্লয় প্রক্রিয়া — ৪ ধাপ

### ধাপ ১ — লোকাল পরিবর্তন GitHub-এ পুশ করুন

VS Code-এর টার্মিনালে (PowerShell):

```powershell
cd "C:\Users\ASUS\OneDrive\Desktop\Aadhar Saas\Aadhar-SaaS"

# কী কী বদলেছে দেখে নিন
git status

git add -A

# ⚠️ backend/public/ এর ডুপ্লিকেট কপিগুলো বাদ দিন (নিচে ব্যাখ্যা আছে)
git reset -- backend/public/admin backend/public/css backend/public/js backend/public/user "backend/public/*.html" "backend/public/*.png" "backend/public/*.webp" "backend/public/logo .png"

git commit -m "এখানে পরিবর্তনের বর্ণনা লিখুন"
git push origin main
```

**কেন `git reset` লাইনটা দরকার?**
আপনার লোকালে `backend/public/` ফোল্ডারের ভিতরে `frontend/` ফোল্ডারের একটা হুবহু কপি পড়ে আছে (index.html, logo.png, admin/, js/ ইত্যাদি)। এগুলো ডিপ্লয়ে ব্যবহার হয় না, কিন্তু কমিট করলে রিপোতে প্রায় ৫ MB ডুপ্লিকেট ছবি ঢুকে যায়। তাই বাদ দেওয়া হয়।

**পুশের আগে চেক করুন — `.env` যেন কখনো না যায়:**

```powershell
git check-ignore -v backend/.env
```

আউটপুট আসা মানে ঠিক আছে (ignore হচ্ছে)। কিছু না এলে **থামুন** — ডাটাবেস পাসওয়ার্ড আর API key পাবলিক হয়ে যাবে।

---

### ধাপ ২ — cPanel Terminal খুলুন

1. যান → https://host.godaddy.com/webhosting/cpanel/account/6c1286a9-a4e8-11f1-8737-7cd30aca5dc4/view
2. GoDaddy-তে লগইন করুন (সেশন প্রায়ই শেষ হয়ে যায়, আবার পাসওয়ার্ড চাইবে)
3. উপরে ডানদিকে কালো বোতাম **cPanel Admin** → ক্লিক
4. cPanel খুললে নিচে **Advanced** সেকশনে → **Terminal**
5. সতর্কবার্তা এলে **"I understand and want to proceed"** ক্লিক করুন

---

### ধাপ ৩ — ডিপ্লয় কমান্ড চালান

Terminal-এ পুরো লাইনটা একবারে পেস্ট করুন:

```bash
cd ~/app && git fetch --depth 1 origin main -q && git reset --hard origin/main -q && cd backend && composer install --no-dev -o --no-interaction -q && php artisan migrate --force && cd ~/public_html && find . -mindepth 1 -maxdepth 1 ! -name storage -exec rm -rf {} + && cp -a ~/app/frontend/. . && cp -a ~/app/backend/public/. . && rm -rf .wrangler && sed -i "s|__DIR__\.'/\.\.|'/home/lb4jd7g16jmr/app/backend|g" index.php && sed -i 's|http://127.0.0.1:8000||g' *.html && cd ~/app/backend && php artisan config:cache && php artisan route:cache && echo DEPLOY_OK
```

শেষে **`DEPLOY_OK`** লেখা এলে সফল। না এলে কোন ধাপে আটকেছে সেটা এররে লেখা থাকবে।

#### এই কমান্ড আসলে কী কী করে

| অংশ | কাজ |
|---|---|
| `git fetch` + `git reset --hard` | GitHub থেকে নতুন কোড নামায়, সার্ভারের লোকাল পরিবর্তন মুছে দেয় |
| `composer install --no-dev` | নতুন PHP প্যাকেজ থাকলে ইনস্টল করে |
| `php artisan migrate --force` | নতুন মাইগ্রেশন থাকলে ডাটাবেসে চালায় |
| `find ... rm -rf` | `public_html` খালি করে, কিন্তু **`storage` symlink রেখে দেয়** |
| `cp -a frontend/. .` | সব HTML, CSS, JS, ছবি কপি করে |
| `cp -a backend/public/. .` | Laravel-এর `index.php` আর `.htaccess` কপি করে |
| প্রথম `sed` | `index.php` ঠিক করে যাতে সে `~/app/backend` খুঁজে পায় |
| দ্বিতীয় `sed` | HTML-এ পড়ে থাকা `http://127.0.0.1:8000` মুছে দেয় |
| `config:cache` + `route:cache` | Laravel-এর ক্যাশ নতুন করে বানায় (দ্রুত হয়) |

---

### ধাপ ৪ — যাচাই করুন (এটা বাদ দেবেন না)

একই Terminal-এ:

```bash
for u in / /login /register /about /contact /shipping /privacy /terms /refund /admin /user/dashboard /api/services; do printf '%-20s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://onlinedigitalservice.xyz$u)"; done
```

**সব লাইনে `200` আসা উচিত।**

পুরনো `.html` লিংক ঠিকমতো redirect হচ্ছে কিনা:

```bash
for u in /index.html /login.html /about.html; do printf '%-16s %s -> %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://onlinedigitalservice.xyz$u)" "$(curl -s -o /dev/null -w '%{redirect_url}' https://onlinedigitalservice.xyz$u)"; done
```

`301` এবং পরিষ্কার URL দেখালে ঠিক আছে।

সবশেষে **ব্রাউজারে গিয়ে নিজে দেখুন** — `Ctrl + Shift + R` দিয়ে হার্ড রিফ্রেশ করুন (পুরনো ক্যাশ যেন না দেখায়), আর `F12` → Console-এ লাল এরর আছে কিনা দেখুন।

> **শিক্ষা:** একবার শুধু `node --check` পাস করেছিল বলে ধরে নেওয়া হয়েছিল কোড ঠিক আছে। কিন্তু একটা ফাংশন নিজেই নিজেকে ডাকছিল (infinite recursion) — সিনট্যাক্স ঠিক ছিল, কিন্তু সাইট ভাঙা ছিল। **ব্রাউজার Console না দেখলে ধরা পড়ত না।**

---

## ৩. বিশেষ ক্ষেত্র

### `.env` পরিবর্তন করতে হলে

`.env` ফাইল git-এ নেই, তাই ডিপ্লয়ে এটা **কখনো বদলায় না**। হাতে বদলাতে হবে:

```bash
cd ~/app/backend
nano .env          # সম্পাদনা করুন, Ctrl+O সেভ, Ctrl+X বের হওয়া
php artisan config:cache
```

**`config:cache` চালানো বাধ্যতামূলক।** না চালালে Laravel পুরনো ক্যাশ করা মান ব্যবহার করতেই থাকবে, আর আপনি বুঝতে পারবেন না কেন পরিবর্তন কাজ করছে না।

প্রোডাকশনে যেগুলো এভাবেই থাকতে হবে:

```
APP_ENV=production      ← local হলে OTP-র কোড স্ক্রিনে দেখা যাবে (মারাত্মক নিরাপত্তা ঝুঁকি)
APP_DEBUG=false         ← true হলে এরর পেজে কোড ও পাসওয়ার্ড ফাঁস হয়
SMS_DRIVER=apitxt       ← local হলে সত্যিকারের SMS যাবে না
```

### শুধু একটা-দুটো ফাইল বদলেছে

পুরো ডিপ্লয় না চালিয়ে দ্রুত:

```bash
cd ~/app && git fetch --depth 1 origin main -q && git reset --hard origin/main -q
cp -a frontend/js/api.js frontend/js/auth.js ~/public_html/js/
cp -a frontend/login.html ~/public_html/login.html
```

তারপর নতুন কপি করা HTML-এ `sed` চালাতে ভুলবেন না:

```bash
cd ~/public_html && sed -i 's|http://127.0.0.1:8000||g' *.html
```

### নতুন মাইগ্রেশন যোগ করলে

ডিপ্লয় কমান্ডেই `php artisan migrate --force` আছে, আলাদা কিছু লাগে না। আউটপুটে `DONE` দেখে নিন।

---

## ৪. `.html` ছাড়া URL (Clean URL) — কীভাবে কাজ করে

সাইটে এখন `/login`, `/about` এভাবে চলে, `.html` দেখায় না। নিয়মগুলো আছে `backend/public/.htaccess` ফাইলে, রিপোর ভিতরে — তাই প্রতি ডিপ্লয়ে নিজে থেকেই চলে আসে, হাতে কিছু করতে হয় না।

তিনটে নিয়ম:

1. `/login.html` → `/login` এ **301 redirect** (ঠিকানা বার পরিষ্কার হয়)
2. `/login` → ভিতরে ভিতরে `login.html` ফাইল দেখায় (redirect ছাড়া)
3. `DirectorySlash Off`

**তৃতীয়টা কেন দরকার ছিল:** `admin.html` ফাইল আর `admin/` ফোল্ডার — দুটোরই নাম `admin`। Apache স্বয়ংক্রিয়ভাবে `/admin` কে `/admin/` ফোল্ডারে পাঠিয়ে দিচ্ছিল, ফলে `/admin` এ `301` আসছিল, লগইন পেজ আসছিল না। `DirectorySlash Off` দিয়ে সেটা বন্ধ করা হয়েছে। (`Options -Indexes` আগে থেকেই আছে, তাই ফোল্ডারের ভিতরের তালিকা কেউ দেখতে পাবে না — নিরাপদ।)

**JS-এ যে ফাঁদ ছিল:** `api.js` আর `auth.js`-এ চেক ছিল `pathname.includes('login.html')`। URL `/login` হয়ে যাওয়ায় এই চেকগুলো মিথ্যা হয়ে যাচ্ছিল, আর 401 এলে পেজ বারবার রিলোড হচ্ছিল। এখন `currentPagePath()` নামে একটা helper আছে যেটা দুই রকম URL-এই কাজ করে।

> ভবিষ্যতে নতুন পেজ বানালে `pathname.includes('...html')` এভাবে চেক লিখবেন না — `currentPagePath()` ব্যবহার করুন।

---

## ৫. সমস্যা ও সমাধান

| সমস্যা | কারণ | সমাধান |
|---|---|---|
| Terminal-এ `connection ended in failure (ABORTED)` | cPanel Terminal প্রায়ই নিজে থেকে বিচ্ছিন্ন হয় | **Reconnect** বোতামে ক্লিক করুন, বা পেজ রিফ্রেশ করুন। লম্বা কমান্ড ভেঙে ছোট ছোট করে চালান |
| cPanel হঠাৎ লগইন পেজ দেখাচ্ছে | cPanel সেশনের মেয়াদ শেষ | GoDaddy পেজ থেকে আবার **cPanel Admin** ক্লিক করুন। পুরনো `cpsess...` লিংক আর কাজ করবে না |
| `git clone`/`fetch` এ `Authentication failed` | রিপো private | GitHub-এ Settings → Danger Zone → কিছুক্ষণের জন্য Public করুন, pull শেষে আবার Private |
| সাইটে পুরনো জিনিস দেখাচ্ছে | ব্রাউজার ক্যাশ | `Ctrl + Shift + R`। তবু না হলে `php artisan config:cache` আবার চালান |
| `.env` বদলেছি কিন্তু কিছু হচ্ছে না | Laravel-এর কনফিগ ক্যাশ | `php artisan config:cache` |
| `500 Internal Server Error` | সাধারণত `index.php`-র path ভুল বা storage-এ লেখার অনুমতি নেই | নিচের ডায়াগনস্টিক দেখুন |
| ছবি বা আপলোড করা ফাইল দেখাচ্ছে না | `storage` symlink মুছে গেছে | `ln -sfn /home/lb4jd7g16jmr/app/backend/storage/app/public /home/lb4jd7g16jmr/public_html/storage` |
| OTP আসছে কিন্তু "Invalid" বলছে | আসলে OTP ভুল নয় — নাম/ইমেল/পাসওয়ার্ড validation ফেল করছে | লাল এরর বার্তাটা মন দিয়ে পড়ুন। পাসওয়ার্ড: কমপক্ষে ৮ অক্ষর, অন্তত একটা অক্ষর ও একটা সংখ্যা |

### `500` এরর হলে যা দেখবেন

```bash
tail -30 ~/app/backend/storage/logs/laravel.log
tail -30 ~/public_html/error_log
```

অনুমতি ঠিক করা:

```bash
cd ~/app/backend && chmod -R 775 storage bootstrap/cache
```

`index.php` ঠিক আছে কিনা (৩টে লাইন আসা উচিত):

```bash
grep -c 'app/backend' ~/public_html/index.php
```

---

## ৬. জরুরি অবস্থা — আগের অবস্থায় ফেরা (Rollback)

ডিপ্লয়ের পর সাইট ভেঙে গেলে আগের কমিটে ফিরে যান:

```bash
cd ~/app
git log --oneline -5              # শেষ ৫টা কমিট দেখুন
git reset --hard <পুরনো-commit-id>
```

তারপর **ধাপ ৩**-এর ডিপ্লয় কমান্ডের `git fetch ... reset --hard origin/main` অংশটুকু বাদ দিয়ে বাকিটা চালান (নাহলে আবার নতুন কোড নেমে আসবে)।

ডাটাবেস কখনো rollback হয় না — মাইগ্রেশন ফেরাতে হলে `php artisan migrate:rollback` (সাবধানে, ডেটা হারাতে পারে)।

---

## ৭. কখনো করবেন না

- ❌ `~/public_html` এ সরাসরি ফাইল এডিট করা — পরের ডিপ্লয়ে মুছে যাবে
- ❌ `.env` ফাইল git-এ কমিট করা — DB পাসওয়ার্ড ও API key ফাঁস হবে
- ❌ প্রোডাকশনে `APP_DEBUG=true` রাখা
- ❌ `frontend/js/config.js` এ `apiOrigin` এ `http://127.0.0.1:8000` বসানো — লাইভ সাইট ভেঙে যাবে (খালি `''` থাকতে হবে; লোকালে এমনিতেই আলাদা লজিক কাজ করে)
- ❌ `~/old-wp-backup` মুছে ফেলা যতক্ষণ না নিশ্চিত হচ্ছেন পুরনো WordPress-এর কিছু আর লাগবে না
- ❌ ডিপ্লয়ের পর যাচাই না করে চলে যাওয়া

---

## ৮. দ্রুত রেফারেন্স — কপি-পেস্ট

```bash
# পুরো ডিপ্লয়
cd ~/app && git fetch --depth 1 origin main -q && git reset --hard origin/main -q && cd backend && composer install --no-dev -o --no-interaction -q && php artisan migrate --force && cd ~/public_html && find . -mindepth 1 -maxdepth 1 ! -name storage -exec rm -rf {} + && cp -a ~/app/frontend/. . && cp -a ~/app/backend/public/. . && rm -rf .wrangler && sed -i "s|__DIR__\.'/\.\.|'/home/lb4jd7g16jmr/app/backend|g" index.php && sed -i 's|http://127.0.0.1:8000||g' *.html && cd ~/app/backend && php artisan config:cache && php artisan route:cache && echo DEPLOY_OK

# যাচাই
for u in / /login /about /admin /api/services; do printf '%-16s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://onlinedigitalservice.xyz$u)"; done

# লগ দেখা
tail -30 ~/app/backend/storage/logs/laravel.log

# ক্যাশ পরিষ্কার
cd ~/app/backend && php artisan config:cache && php artisan route:cache

# storage লিংক ঠিক করা
ln -sfn /home/lb4jd7g16jmr/app/backend/storage/app/public /home/lb4jd7g16jmr/public_html/storage

# সার্ভারে এখন কোন কমিট আছে
cd ~/app && git log --oneline -1
```

---

## ৯. এখনো বাকি আছে

- [ ] GitHub রিপো **Private** করা (pull-এর জন্য Public করা হয়েছিল)
- [ ] অ্যাডমিন পাসওয়ার্ড বদলানো — `admin@onlinedigitalservice.xyz`
- [ ] `/about` আর `/contact` পেজে placeholder লেখা বদলানো — `[Owner / Proprietor Full Name]`, `[City, State]`, `[Full Business Address]`
- [ ] Razorpay key ও অ্যাডমিন UPI ID বসানো
- [ ] `~/old-wp-backup` মুছে ফেলা (৩৩৭ MB)
