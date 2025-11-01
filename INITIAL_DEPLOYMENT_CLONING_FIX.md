# Initial Deployment & Project Cloning Fix

## 🐛 **Problem:**

The deployment script was failing because it assumed the project was already cloned on the server:

```bash
cd /var/www/samschool-backend
git pull origin main  # ❌ Fails if project doesn't exist
```

## ✅ **Solution Implemented:**

The deployment script now automatically handles initial project cloning:

### **1. Automatic Directory Creation**

-   Creates project directory if it doesn't exist
-   Sets proper ownership permissions

### **2. Automatic Repository Detection**

-   Checks if `.git` directory exists
-   If not, automatically clones the repository
-   If exists, pulls latest changes

### **3. Smart Repository URL Handling**

-   Uses `REPO_URL` secret if provided (recommended for private repos)
-   Falls back to auto-generating from `github.repository` context
-   Supports both SSH (`git@github.com:...`) and HTTPS (`https://github.com/...`) formats

## 🔧 **How It Works:**

```bash
# Script now checks and handles both scenarios:

# Scenario 1: Project doesn't exist
if [ ! -d ".git" ]; then
  echo "Cloning repository..."
  git clone "$REPO_URL" .
fi

# Scenario 2: Project exists
else
  echo "Pulling latest changes..."
  git pull origin main
fi
```

## 📋 **GitHub Secrets Setup:**

### **Required Secrets:**

1. **`SERVER_HOST`** - Your VPS IP address or domain
2. **`SERVER_USER`** - SSH username (usually `root` or `ubuntu`)
3. **`SERVER_SSH_KEY`** - Private SSH key for authentication
4. **`SERVER_PORT`** - SSH port (optional, defaults to 22)

### **Optional Secrets (with defaults):**

5. **`PROJECT_PATH`** - Project directory (defaults to `/var/www/samschool-backend`)
6. **`REPO_URL`** - Repository URL (defaults to `git@github.com:{owner}/{repo}.git`)

### **Setting Up REPO_URL Secret:**

#### **For Private Repositories (Recommended):**

```bash
# In GitHub: Settings > Secrets and variables > Actions
# Add secret: REPO_URL

# SSH format (requires SSH key setup on server):
git@github.com:your-username/samschool-backend.git

# HTTPS format (requires access token):
https://github.com/your-username/samschool-backend.git
```

#### **For Public Repositories:**

-   No `REPO_URL` secret needed
-   Auto-generates from `github.repository` context

## 🚀 **First-Time Deployment Steps:**

### **1. Ensure SSH Access:**

```bash
# Test SSH connection from GitHub Actions runner
ssh -i ~/.ssh/deploy_key user@your-server.com
```

### **2. Set Up GitHub Secrets:**

Go to your repository → Settings → Secrets and variables → Actions

Add these secrets:

-   `SERVER_HOST`: `your-server.com` or IP
-   `SERVER_USER`: `root` or `ubuntu`
-   `SERVER_SSH_KEY`: Your private SSH key
-   `SERVER_PORT`: `22` (or your custom port)
-   `PROJECT_PATH`: `/var/www/samschool-backend` (optional)
-   `REPO_URL`: `git@github.com:your-username/samschool-backend.git` (optional)

### **3. Server Prerequisites:**

Ensure your server has:

-   Git installed: `sudo apt-get install git`
-   PHP 8.2+ installed
-   Composer installed
-   Node.js 20+ installed
-   MySQL/MariaDB installed
-   Redis installed
-   Nginx/Apache configured

### **4. SSH Key Setup (for private repos):**

If using SSH format, add your server's SSH public key to GitHub:

```bash
# On your server, generate SSH key if needed
ssh-keygen -t ed25519 -C "deploy@your-server"

# Add public key to GitHub: Settings > SSH and GPG keys
cat ~/.ssh/id_ed25519.pub
```

### **5. Trigger Deployment:**

Push to `main` branch or manually trigger via GitHub Actions

## 🔍 **Troubleshooting:**

### **Issue: "Repository not found"**

-   **Solution**: Check `REPO_URL` secret is correct
-   For private repos, ensure SSH key is added to GitHub

### **Issue: "Permission denied"**

-   **Solution**: Check directory permissions:

```bash
sudo chown -R $USER:$USER /var/www/samschool-backend
sudo chmod -R 755 /var/www/samschool-backend
```

### **Issue: "Git clone fails"**

-   **Solution**: Ensure Git is installed and SSH keys are configured:

```bash
# Test SSH access to GitHub
ssh -T git@github.com
```

### **Issue: "Directory creation fails"**

-   **Solution**: Ensure user has sudo permissions:

```bash
# Add user to sudo group
sudo usermod -aG sudo $USER
```

## 📝 **Deployment Flow:**

```
1. GitHub Actions triggers on push to main
2. Connects to server via SSH
3. Checks if PROJECT_DIR exists → Creates if needed
4. Checks if .git exists → Clones if needed, else pulls
5. Installs dependencies (Composer + NPM)
6. Builds assets (Vite)
7. Runs migrations
8. Sets up tenant databases
9. Clears/caches configs
10. Restarts services
11. Health check
```

## ✅ **What's Fixed:**

-   ✅ **Automatic project cloning** on first deployment
-   ✅ **Directory creation** if it doesn't exist
-   ✅ **Smart git repository detection**
-   ✅ **Fallback branch support** (main or master)
-   ✅ **Error handling** with clear messages
-   ✅ **Repository URL flexibility** (SSH or HTTPS)

---

The deployment script now handles both initial setup and subsequent updates automatically! 🎉
