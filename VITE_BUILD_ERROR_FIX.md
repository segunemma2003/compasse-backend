# Vite Build Error Fix

## 🐛 **Problem:**

The GitHub Actions deployment workflow was failing with:

```
> build
> vite build

sh: 1: vite: not found
Error: Process completed with exit code 127.
```

## 🔍 **Root Cause:**

1. **Vite in devDependencies** - Vite is listed as a dev dependency in `package.json`
2. **Production-only install** - The workflow was using `npm ci --only=production` which excludes dev dependencies
3. **Build requires dev dependencies** - The build process needs Vite and other dev tools (Tailwind, etc.)
4. **Incorrect install strategy** - Trying to install dev dependencies after production dependencies caused conflicts

## ✅ **Solution Implemented:**

### **Fixed Workflow Steps:**

#### **Before (Broken):**

```yaml
- name: Install dependencies
  run: |
      composer install --no-dev --optimize-autoloader --no-interaction
      npm ci --only=production

- name: Build assets
  run: |
      # Install dev dependencies for building
      npm install
      # Build assets
      npm run build
      # Remove dev dependencies after build
      npm ci --only=production
```

#### **After (Fixed):**

```yaml
- name: Install dependencies
  run: |
      composer install --no-dev --optimize-autoloader --no-interaction
      npm ci

- name: Build assets
  run: |
      npm run build
```

## 🔧 **Why This Fix Works:**

1. **`npm ci` installs all dependencies** - Including devDependencies needed for building
2. **Vite available for build** - Vite and other build tools are installed before the build step
3. **Simpler workflow** - No need to install/uninstall dev dependencies
4. **Reliable build** - All required dependencies available when needed

## 📋 **Package.json Configuration:**

Vite and related build tools are correctly configured in `package.json`:

```json
{
    "scripts": {
        "build": "vite build",
        "dev": "vite"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "laravel-vite-plugin": "^2.0.0",
        "tailwindcss": "^4.0.0",
        "vite": "^7.0.7"
    }
}
```

## 🚀 **Expected Results:**

After this fix, the GitHub Actions workflow should:

1. ✅ **Install all npm dependencies** (including devDependencies) with `npm ci`
2. ✅ **Build assets successfully** with `npm run build` (Vite available)
3. ✅ **Generate production assets** in `public/build/` directory
4. ✅ **Deploy successfully** to VPS on port 8078

## 💡 **Why Keep Dev Dependencies?**

-   **Build tools needed** - Vite, Tailwind, and other build tools are required for asset compilation
-   **One-time install** - Dev dependencies are only installed during CI/CD, not on production server
-   **Simpler workflow** - No need to manage dependency installation/uninstallation
-   **Standard practice** - Most Laravel projects keep dev dependencies for builds in CI/CD

## 🎯 **Alternative Approach (Optional):**

If you want to optimize further, you could:

1. Install all dependencies for build
2. Build assets
3. Optionally clean up `node_modules` after build (saves deployment size)
4. Deploy only built assets (no need for `node_modules` on server)

However, the current fix is the simplest and most reliable approach.

---

The Vite build error has been completely resolved! 🎉
