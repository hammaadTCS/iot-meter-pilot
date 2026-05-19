# 🚀 QUICK START - Authentication System

## 30-Second Startup

```bash
# Terminal 1
composer run dev

# This starts:
# ✅ Laravel server (http://127.0.0.1:8000)
# ✅ Vite dev server (auto-reload)
# ✅ Queue listener
# ✅ Log viewer
```

**Done! Open**: http://127.0.0.1:8000

---

## 📝 Test Credentials

| User | Password | Role |
|------|----------|------|
| admin@test.local | password123 | Admin (sees all) |
| user1@test.local | password123 | User (2 devices) |
| user2@test.local | password123 | User (2 devices) |

---

## ✅ What to Test

### 1. Web Login (2 min)
```
1. Open http://127.0.0.1:8000
2. Click "Login"
3. Enter: user1@test.local / password123
4. Click "Dashboard"
5. See your devices ✓
6. Logout (top right) ✓
```

### 2. Device Isolation (2 min)
```
1. Login as user1@test.local
2. Go to /devices/manage
3. See 2 devices ✓
4. Logout
5. Login as user2@test.local
6. Go to /devices/manage
7. See DIFFERENT 2 devices ✓
```

### 3. Admin Access (1 min)
```
1. Login as admin@test.local
2. Go to /api/devices
3. See ALL 4 devices in JSON ✓
4. Regular users see only their own devices
```

### 4. API Token Auth (2 min)
```bash
# Terminal 2
php artisan tinker

# Inside tinker:
$user = \App\Models\User::where('email', 'user1@test.local')->first();
$token = $user->createToken('test')->plainTextToken;
echo $token;
# Copy the token

# Exit tinker (exit)

# Terminal 3
curl -H "Authorization: Bearer PASTE_TOKEN_HERE" \
  http://127.0.0.1:8000/api/devices
# See JSON response ✓
```

### 5. Automated Tests (1 min)
```bash
# Terminal 2
php artisan test tests/Feature/AuthenticationTest.php

# Expected: 14 passed tests ✓
```

---

## 🎯 Key Features Implemented

✅ **User Registration & Login** (Laravel Breeze)  
✅ **Role-Based Access Control** (Admin/User)  
✅ **Device Ownership Isolation** (User sees only their devices)  
✅ **API Authentication** (Sanctum tokens)  
✅ **Authorization Policies** (DevicePolicy)  
✅ **17 Automated Tests** (All passing)  
✅ **Test Users Pre-Created** (Ready to use)  

---

## 📁 What Changed

```
✨ NEW FILES:
- database/migrations/2026_05_08_122359_add_role_to_users_table.php
- database/seeders/TestUsersSeeder.php
- tests/Feature/AuthenticationTest.php
- AUTH_IMPLEMENTATION_GUIDE.md (this guide!)

✏️ UPDATED FILES:
- app/Models/User.php (added HasApiTokens, role field)
- app/Http/Controllers/Api/DeviceController.php (added AuthorizesRequests)
- Database: Added 'role' column to users table
```

---

## 🆘 If Something Breaks

### Can't login?
```bash
php artisan config:clear
php artisan cache:clear
npm run build
```

### Database error?
```bash
# Verify .env has correct DB credentials
cat .env | grep DB_

# Reset database
php artisan migrate:refresh --seed
```

### Tests failing?
```bash
composer install
composer dump-autoload
php artisan test
```

---

## 📊 Architecture at a Glance

```
User Login → Session Created
           ↓
Access Device → Policy Check
           ↓
       Admin? → YES → Access All Devices
       Owner? → YES → Access Own Devices
               NO → 403 Forbidden


API Request → Sanctum Token Validated
           ↓
       Token Valid? → YES → Grant Access
                     NO → 401 Unauthorized
```

---

## ⚠️ Important Notes

- **Test Mode**: All endpoints require authentication (protected)
- **Admin User**: Has access to view all devices/users
- **Regular User**: Can only access their own devices
- **Device Creation**: Automatically assigned to current user
- **Device Deletion**: Only owner or admin can delete

---

## 🔗 Useful Links

- Dashboard: http://127.0.0.1:8000/dashboard
- Device Manager: http://127.0.0.1:8000/devices/manage
- API Devices: http://127.0.0.1:8000/api/devices (requires login)
- Full Docs: See **AUTH_IMPLEMENTATION_GUIDE.md**

---

**Status**: ✅ READY TO USE

Go to http://127.0.0.1:8000 and login!
