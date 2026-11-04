# CampusIQ Cryptographic Architecture & Password Security Notes

## 1. Executive Summary & Security Objectives
Modern web application security requires robust protection of user credentials against multiple adversarial attack vectors, ranging from offline database leaks (SQL injection dumps) to high-speed hardware-accelerated brute-force attacks (GPU/ASIC rigs). 

CampusIQ implements a **Two-Tier Hybrid Cryptographic Architecture** combining:
1. **Server-Side Secret Key Peppering** via `HMAC-SHA256`
2. **Slow, Memory-Hard Key Stretching & Unique Salting** via `Bcrypt (Blowfish crypt)` with a Work Factor of 10 ($2^{10} = 1,024$ key expansion rounds)

This document provides academic foundations, computational complexity evaluations, and implementation specifications for the CampusIQ authentication engine.

---

## 2. Fundamental Cryptographic Primitives: Hashing vs. Salting vs. Peppering

| Property | Plain Hash (Broken) | Cryptographic Salt | Server Pepper |
| :--- | :--- | :--- | :--- |
| **Typical Primitive** | MD5, SHA-1, plain SHA-256 | Cryptographically Secure Random Salt | 256-bit (64 hex) Server Secret Key |
| **Storage Location** | Database (User table) | Database (Stored within hash string) | Server Environment / Code (Never in DB) |
| **Entropy Scope** | Global (Deterministic) | Unique per user & per hash invocation | System-wide secret |
| **Primary Defense** | None (Vulnerable to lookup tables) | Defeats **Rainbow Tables** & Cross-Account Collision | Defeats **Database Dump Leaks** & Offline Cracking |
| **CampusIQ Usage** | ❌ Prohibited | ✅ 22-character random base64 salt | ✅ 64-char hex key (`CAMPUSIQ_PASSWORD_PEPPER`) |

---

## 3. The Two-Tier Hybrid Pipeline in CampusIQ

```
                          [ Raw Plaintext Password ]
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ TIER 1: SERVER-SIDE PEPPERING (HMAC-SHA256)                                 │
│                                                                             │
│   $pepper  = campusPasswordPepper(); // Server Environment (64-hex key)      │
│   $preHash = hash_hmac('sha256', $password, $pepper);                       │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼ (Normalized 64-character hex string)
┌─────────────────────────────────────────────────────────────────────────────┐
│ TIER 2: ADAPTIVE BCRYPT KEY STRETCHING (Blowfish $2y$)                      │
│                                                                             │
│   $salt    = random_bytes(16); // 22-char base64 encoded                    │
│   $rounds  = 2^10 = 1,024 iterations                                        │
│   $hash    = password_hash($preHash, PASSWORD_DEFAULT);                     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
             [ 60-Character Stored Hash in users.password_hash ]
                    $2y$10$e9V...22CharsSalt...31CharsDigest
```

### Why HMAC-SHA256 First?
1. **Elimination of the 72-Byte Bcrypt Boundary**:
   Standard Bcrypt truncates passwords after the 72nd byte. Passwords such as `VeryLongPassword...1` and `VeryLongPassword...2` would produce identical hashes if they only differ after character 72. Pre-hashing with HMAC-SHA256 compresses and normalizes any password length (from 1 to 10,000 characters) into an exact 64-character hexadecimal digest, completely eliminating this architectural limitation.
2. **Decoupled Key Storage**:
   The pepper key is isolated from the database. In an intrusion scenario where an attacker obtains the SQL database dump (e.g., via SQL injection), they **cannot crack the passwords** because the HMAC operation cannot be initiated without the server-side pepper.

---

## 4. Anatomy of the Stored 60-Character Bcrypt String

Every password in CampusIQ's `users` table is stored as an exact 60-character string formatted according to the Modular Crypt Format:

```
$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy
├──┘├──┘└────────────────────┘└──────────────────────────────┘
 │    │           │                         │
 │    │           │                         └── Ciphertext Digest (31 chars)
 │    │           └──────────────────────────── Unique Salt (22 base64 chars)
 │    └──────────────────────────────────────── Cost Factor ($2^{10} = 1,024$ rounds)
 └───────────────────────────────────────────── Algorithm Identifier ($2y$ Blowfish)
```

- **`$2y$`**: Identifies the 8-bit hardened PHP implementation of the Eksblowfish algorithm.
- **`$10$`**: Represents the exponential work factor $C = 10$, requiring $2^{10} = 1,024$ expansion cycles.
- **Next 22 characters**: The cryptographically random salt generated per-user.
- **Last 31 characters**: The resulting 192-bit ciphertext digest.

---

## 5. Mathematical & Computational Complexity Analysis

### GPU / Hardware Attack Resistance
Standard cryptographic hash functions like SHA-256 or MD5 are designed for high throughput and rapid computation. A single modern GPU (e.g., NVIDIA RTX 4090) can compute approximately:
$$\approx 15,000,000,000\text{ (15 Billion) SHA-256 hashes per second}$$

In contrast, Bcrypt utilizes the **Eksblowfish** key schedule, which is deliberately **memory-hard** and computationally intensive. The algorithm requires 4 KB of memory for S-boxes that must be dynamically updated $2^{10} = 1,024$ times per verification. Because GPU streaming multiprocessors share limited local cache, parallel execution stalls due to memory bandwidth starvation.

On the same hardware, Bcrypt cost 10 throttles candidate evaluation down to:
$$\approx 10\text{ to }15\text{ attempts per second per core}$$

This provides a **$\mathbf{1,000,000,000\times}$ (One Billion Fold) slowdown factor** against brute-force cracking attempts.

---

## 6. Regulatory & Industry Standards Alignment

1. **OWASP (Open Web Application Security Project)**:
   - Complies fully with the *OWASP Password Storage Cheat Sheet*.
   - Mandates work factor $\ge 10$ and encourages application-level pepper isolation.
2. **NIST Special Publication 800-63B (Digital Identity Guidelines)**:
   - Mandates slow, salted key derivation functions.
   - Forbids plain truncation and prohibits outdated algorithms (MD5, SHA-1).
3. **Data Protection Legislation (GDPR / Ghana Data Protection Act 2012 - Act 843)**:
   - Section 28 mandates technical measures to safeguard personal data. Preserving salted password hashes ensures zero plain-text credential exposure even during user deletion and restoration cycles.

---

## 7. Reversibility & Assignment Cleanup Guide

All demonstration components added for this presentation are completely modular and non-destructive:
- **Demo Portal**: [`admin/security_demo.php`](file:///c:/xampp/htdocs/campusIQ/admin/security_demo.php)
- **Archive Table**: `campusiq.deleted_users_archive`
- **Secondary DB**: `campusiq_archive.deleted_users`
- **Integrity Baseline**: `storage/security_baseline/`

To remove the presentation features after the evaluation without impacting live operations:
1. Remove `['label' => 'Security Demo', ...]` from [`includes/header.php`](file:///c:/xampp/htdocs/campusIQ/includes/header.php).
2. Delete [`admin/security_demo.php`](file:///c:/xampp/htdocs/campusIQ/admin/security_demo.php).
3. Optionally drop table `deleted_users_archive` via SQL (`DROP TABLE IF EXISTS deleted_users_archive;`).
