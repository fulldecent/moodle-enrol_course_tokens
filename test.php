<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test API Payload</title>
</head>
<body>
    <h1>Test API: Create Tokens</h1>
    <form id="apiTestForm">
        <label for="password">Password:</label>
        <input type="text" id="password" name="password" value="dd4b21e9ef71e1291183a46b913ae6f2" required><br><br>
        
        <label for="course_id">Course ID:</label>
        <input type="number" id="course_id" name="course_id" value="5" required><br><br>
        
        <label for="email">Email:</label>
        <input type="email" id="email" name="email" value="testuser@example.com" required><br><br>
        
        <label for="quantity">Quantity:</label>
        <input type="number" id="quantity" name="quantity" value="2" required><br><br>
        
        <label for="firstname">First Name:</label>
        <input type="text" id="firstname" name="firstname" value="John" required><br><br>
        
        <label for="lastname">Last Name:</label>
        <input type="text" id="lastname" name="lastname" value="Doe" required><br><br>
        
        <label for="group_account">Group Account (Optional):</label>
        <input type="text" id="group_account" name="group_account" value="Corporate Inc"><br><br>
        
        <label for="extra_json">Extra JSON (Optional):</label>
        <textarea id="extra_json" name="extra_json" rows="4" cols="50">{ "role": "student" }</textarea><br><br>
        
        <button type="button" onclick="sendPayload()">Send Payload</button>
    </form>

    <h2>Response:</h2>
    <pre id="responseOutput"></pre>

    <script>
        async function sendPayload() {
            const url = "api-do-create-token.php"; // Update the URL
            
            // Prepare JSON payload from form inputs
            const payload = {
                password: document.getElementById('password').value,
                course_id: parseInt(document.getElementById('course_id').value),
                email: document.getElementById('email').value,
                quantity: parseInt(document.getElementById('quantity').value),
                firstname: document.getElementById('firstname').value,
                lastname: document.getElementById('lastname').value,
                group_account: document.getElementById('group_account').value || '',
                extra_json: document.getElementById('extra_json').value ? JSON.parse(document.getElementById('extra_json').value) : null
            };

            try {
                // Send the POST request with JSON payload
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                // Display the response
                const result = await response.json();
                document.getElementById('responseOutput').textContent = JSON.stringify(result, null, 2);
            } catch (error) {
                document.getElementById('responseOutput').textContent = `Error: ${error.message}`;
            }
        }
    </script>
</body>
</html>
