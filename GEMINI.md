# Project Overview

This project is a PHP-based WhatsApp chatbot. It uses a database-driven approach to manage conversation flows, allowing for dynamic and flexible interactions with users.

## Key Technologies

*   **Backend:** PHP
*   **Database:** MySQL
*   **Web Server:** XAMPP (Apache)
*   **API:** WhatsApp Graph API

## Architecture

The application is centered around a webhook (`webhook_v3.php`) that processes incoming messages from WhatsApp. The core logic relies on a system of "flujos" (flows) and "nodos" (nodes) stored in a MySQL database. Each user interaction transitions them through different nodes within a flow, creating a structured conversation.

The database schema, detailed in `documentacion/Flujo_Bot_DB.md`, defines the structure for flows, nodes, user sessions, and more. The conversation flows themselves are illustrated in `documentacion/Flujo_Bot.md` and `documentacion/Flujos_Bolsa_Trabajo.md`.

## Building and Running

This project is designed to be run on a web server with PHP and a MySQL database.

1.  **Database Setup:**
    *   Import the database schema from the documentation or a SQL dump file if available.
    *   Configure the database connection in `db.php` and `db_bot.php` with the correct host, database name, username, and password.

2.  **Webhook Configuration:**
    *   Deploy the `webhook_v3.php` file to a publicly accessible URL.
    *   Configure the WhatsApp App to send webhook events to this URL.
    *   Set the `VERIFY_TOKEN` in the `whatsapp_config` table in the database.

3.  **Running:**
    *   The application is event-driven and runs when it receives a message from the WhatsApp API.
    *   Logs are written to `whatsapp_api_log.txt` and `webhook_error.log`.

## Development Conventions

*   The code follows a procedural style.
*   Database interactions are handled using the PDO extension.
*   Conversation logic is managed through a state machine implemented in the `continuarFlujo` function in `webhook_v3.php`.
*   Dynamic content, such as lists of options, is fetched from the database.
