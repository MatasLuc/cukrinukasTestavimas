# 🤖 AI Development Guidelines (Cukrinukas)

This file constitutes the "Constitution" for the AI assistant (Gemini/ChatGPT/Claude). 
BEFORE writing any code or response, the AI MUST read and strictly adhere to these instructions.

---

## ⚠️ 1. CRITICAL RULES (ZERO TOLERANCE)

1.  **FULL CODE ONLY:** Never provide code snippets with comments like `// ... rest of the code`.
    * Every file output must be **complete** from `<?php` to the end.
    * **Goal:** The user must be able to **Copy -> Select All -> Paste** without manual merging.
2.  **STABILITY & PRESERVATION:**
    * **Design:** Strictly **PROHIBITED** to modify existing design, CSS classes, or HTML structure unless explicitly requested.
    * **Features:** Strictly **PROHIBITED** to remove or "optimize away" existing functionality. New code must extend, not break, old code.
3.  **NO IMPROVISATION:** If a task is ambiguous, unclear, or lacks context – **DO NOT GUESS**.
    * The AI must stop and ask clarifying questions.
    * It is better to ask than to provide a solution that breaks the system.
4.  **LANGUAGE:**
    * **Communication with User:** Lithuanian (Lietuvių k.).
    * **Code Comments:** Lithuanian (Lietuvių k.).
5.  **SECURITY:** All SQL queries MUST use **PDO Prepared Statements**. No direct variable injection into SQL strings.

---

## 🛠 2. Tech Stack

* **Backend:** PHP (Native/Vanilla). No Frameworks (Laravel/Symfony etc. are forbidden).
* **Database:** MySQL / MariaDB.
* **DB Connection:** Use `db.php` (PDO).
* **Frontend:** HTML5, CSS, Vanilla JS.
* **Payments:** Stripe (`stripe_checkout.php`, `stripe_webhook.php`) & Paysera (`libwebtopay`).
* **Email:** `PHPMailer` (located in `lib/PHPMailer`).

---

## 📂 3. Project Structure & Conventions

AI must adhere to this structure when creating or referencing files:

* **`admin/`** – Admin panel files. Must include Session Auth check.
* **`lib/`** – External libraries (Do not touch unless updating).
* **`uploads/`** – Image storage.
* **`db.php`** – Database config (Must be included where DB is needed).
* **`layout.php` / `header.php`** – UI layouts. Do not duplicate full HTML structures; use existing layout includes.

**Configuration & Security:**
* **API Keys & Passwords:** Never hardcode sensitive data. Always retrieve settings from the `.env` file (parsed via `env.php`).

**Code Style:**
* Variables: `snake_case` (e.g., `$user_id`, `$order_total`).
* Functions: `snake_case` or `camelCase` (follow the existing file context).
* Error Handling: Use `try { ... } catch (PDOException $e) { ... }`. Log crucial background errors (especially in webhooks) using `logger.php`.

**Session Management:**
* Rely on established `$_SESSION` variables (e.g., checking if user or admin is logged in) before executing restricted logic.

---

## 📝 4. Current Task Specifics

* *[Place for your current temporary notes]*

---

## 🔄 5. Workflow Protocol

1.  AI receives a prompt.
2.  AI reads `README.md` guidelines.
3.  **Ambiguity Check:** If the task is unclear -> AI asks the user.
4.  **Execution:** AI provides the **FULL** file code, ensuring existing design and features remain intact.
