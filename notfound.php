<?php
// This page used to return HTTP 200 for every unmatched URL (including
// via the .htaccess catch-all), which tells Google "this page exists
// and is fine to index" - exactly backwards for a missing page. A real
// 404 status keeps broken/old links out of the search index instead of
// polluting it with "not found" pages that look successful.
if (!headers_sent()) { http_response_code(404); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Not Found</title>
    <meta name="robots" content="noindex,follow">
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <style>
        body {
            text-align: center;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: rgb(255, 255, 255);
        }
        .container {
            margin-top: 10%;
        }
        h1 {
            font-size: 50px;
            color: #42c3cf;
        }
        p {
            font-size: 20px;
            color: #666;
        }
        a {
            color: #42c3cf;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="col-md-5 d-flex flex-column justify-content-center align-items-center p-4">
            <img src="assets/images/notfound.jpg" alt="Page Not Found" class="img-fluid logo-image" style="width: 250px;">
        </div>
        <h1>404 - Page Not Found</h1>
        <p>Sorry, the page you are looking for is not available.</p>
        <p><a href="/Home/">Back to Home</a></p>
    </div>
</body>
</html>