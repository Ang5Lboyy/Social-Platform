# Social Platform

A lightweight PHP social networking platform built for local development using MAMP. It supports user registration, login, posting, following, messaging, comments, and AI-powered image and post generation endpoints.

## Features

- User registration and authentication
- Profile pages and friends/follows handling
- Feed display and single post view
- Post creation with optional AI-generated content
- Image search and AI image generation
- Like, comment, share, and direct messaging functionality
- Simple MVC-style routing under `routes/`

## Project Structure

- `app/`
  - `config.php` — application configuration
  - `db.php` — database connection
  - `functions.php` — shared helper functions
- `public/`
  - `index.php` — front controller
  - `header.php`, `footer.php` — layout fragments
- `routes/` — individual route handlers
- `templates/`
  - `layout.php` — main layout template
- `config.local.php` — local secrets and API keys (not committed)

## Requirements

- PHP 8.0+ (recommended)
- MySQL / MariaDB
- MAMP or another local development stack

## Installation

1. Copy the project into your local web root, for example:
   `C:\MAMP\htdocs\Social-Platform`

2. Create a `config.local.php` file next to `config.php` with the required API keys:

```php
<?php
// config.local.php
define('GEMINI_API_KEY', 'your_gemini_api_key');
define('GEMINI_MODEL', 'gemini-3.6-flash');
define('UNSPLASH_ACCESS_KEY', 'your_unsplash_access_key');
```

3. Import the database schema and sample data from `angel_barseghyan2.sql` into your local MySQL database.

4. Update database connection settings in `app/db.php` if needed.

5. Open the app in your browser via MAMP, e.g. `http://localhost/Social-Platform/public/index.php` or configure your virtual host.

## Database

The project uses the following main tables:

- `users`
- `post`
- `comment`
- `likes`
- `friends`
- `follows`
- `messages`

Import `angel_barseghyan2.sql` to create the schema and seed sample records.

## Usage

- Register an account using the registration page.
- Log in to create and view posts.
- Browse the feed, like content, comment, and share posts.
- Use the search and AI generation routes for image/post creation.

## Notes

- `config.local.php` is excluded from version control via `.gitignore`.
- Keep your API keys private and do not commit them.
- The project is intended for local development and learning.

## Contact

For improvements or troubleshooting, inspect the route files under `routes/` and the helper functions in `app/functions.php`.
