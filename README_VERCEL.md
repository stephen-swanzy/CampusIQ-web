# CampusIQ Vercel Deployment Guide

This repository is configured to run **CampusIQ (PHP)** seamlessly on [Vercel](https://vercel.com) using the `vercel-php` serverless runtime.

---

## 1. What You Need Before Deploying

Because Vercel is a serverless platform, it runs your PHP code in isolated functions and **does not host MySQL internally**. 

Your database must be accessible over the internet. You have two options:
- **Option A (Free Cloud MySQL)**: Create a free database on [Aiven](https://aiven.io), [TiDB Cloud](https://tidbcloud.com), or [PlanetScale].
  - Import `database/campusiq.sql` or `database/campusiq_production.sql`.
- **Option B (Remote Database)**: If you already have a remote MySQL host, you can plug in its connection details.

---

## 2. Environment Variables in Vercel

When importing your project into Vercel, go to **Settings > Environment Variables** and add:

| Key | Example Value | Description |
| :--- | :--- | :--- |
| `DB_HOST` | `gateway01.us-east-1.prod.aws.tidbcloud.com` | Your remote MySQL host address |
| `DB_PORT` | `4000` or `3306` | MySQL port |
| `DB_NAME` | `campusiq` | Database name |
| `DB_USER` | `admin` | Database username |
| `DB_PASS` | `YourSecretPassword` | Database password |
| `APP_URL` | `https://your-project.vercel.app` | Your Vercel domain |

---

## 3. How to Deploy to Vercel

### Method A: Via GitHub (Recommended)
1. In this folder (`C:\xampp\htdocs\campusIQ-vercel`), initialize git:
   ```powershell
   cd C:\xampp\htdocs\campusIQ-vercel
   git init
   git add .
   git commit -m "Initial commit for Vercel deployment"
   ```
2. Create a new repository on your GitHub account (e.g. `campusiq-web`).
3. Push this code to GitHub:
   ```powershell
   git remote add origin https://github.com/<your-username>/campusiq-web.git
   git branch -M main
   git push -u origin main
   ```
4. Log into [vercel.com](https://vercel.com), click **Add New > Project**, select your GitHub repository, add the Environment Variables above, and click **Deploy**.

### Method B: Via Vercel CLI
If you have Vercel CLI installed:
```powershell
npm i -g vercel
cd C:\xampp\htdocs\campusIQ-vercel
vercel
```

---

## 4. Key Notes on Serverless Architecture
- **File Uploads**: Files uploaded via forms to local directories (`uploads/`) on Vercel are ephemeral (they reset when functions restart). For production persistent file uploads, connect Cloudinary, AWS S3, or Supabase Storage.
- **Sessions**: PHP sessions work per user session; make sure cookie settings match HTTPS on Vercel.
