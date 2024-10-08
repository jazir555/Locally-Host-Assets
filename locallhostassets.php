# Main firewall variable
set $8g_block 0;

# Rate limiting zone for brute force protection
limit_req_zone $binary_remote_addr zone=8g_limit:10m rate=10r/s;

# 8G:[QUERY STRING]
# Block malicious JavaScript, XSS, and DOM manipulation patterns (refined to avoid legitimate traffic)
if ($query_string ~* "(document\.cookie|alert\(|prompt\(|confirm\()" ) { set $8g_block 1; }
if ($query_string ~* "(on(load|error|mouseover|mouseout|focus|blur|scroll|submit|reset))" ) { set $8g_block 1; }

# Allow safe DOM manipulations and standard JavaScript usage, block obfuscated patterns
if ($query_string ~* "(eval\(|base64_decode\(|gzinflate\(|innerHTML|outerHTML|innerText|outerText)" ) { set $8g_block 1; }

# Refined SQL injection detection (focus on high-risk patterns)
if ($query_string ~* "(union(\s|%20)select|information_schema|group_concat|substring|eval\()" ) { set $8g_block 1; }

# Allow legitimate search queries but block known attack vectors
if ($query_string ~* "(sleep\(|benchmark\(|ord\(|mid\()" ) { set $8g_block 1; }
if ($query_string ~* "(case when|having|cast|floor\()" ) { set $8g_block 1; }

# Block directory traversal and system access attempts
if ($query_string ~* "(etc/(passwd|shadow)|proc/self|input=|ftp://|file://)" ) { set $8g_block 1; }

# Block common command injection patterns
if ($query_string ~* "(cmd|nc|bash|sh|curl|wget|chmod|ls|cat)" ) { set $8g_block 1; }

# Allow basic file uploads and harmless data requests, block dangerous input vectors
if ($query_string ~* "(php://input|php://filter|data:image)" ) { set $8g_block 1; }

# Prevent SSRF, local file inclusion, and excessive query length (without over-blocking)
if ($query_string ~* "(127\.0\.0\.1|localhost|169\.254\.)" ) { set $8g_block 1; }
if ($query_string ~* "([a-z0-9]{3000,})" ) { set $8g_block 1; }

# 8G:[REQUEST URI]
# Block sensitive paths in WordPress, while allowing access to common files
if ($request_uri ~* "(wp-admin/install\.php|wp-content/uploads/.*\.php|wp-login\.php)" ) { set $8g_block 1; }

# Block dangerous PHP functions, obfuscation, and shell commands
if ($request_uri ~* "(eval\(|shell_exec\(|proc_open\(|popen\(|phar://)" ) { set $8g_block 1; }

# Prevent access to sensitive system files and directories
if ($request_uri ~* "(/)(etc|var|tmp|proc|dev|root|lib|wp-includes|config|log)" ) { set $8g_block 1; }

# 8G:[USER AGENT]
# Block known malicious scanners, while allowing legitimate bots
if ($http_user_agent ~* "(acunetix|dirbuster|nuclei|nikto|sqlmap|burpsuite|xsser|masscan|arachni)" ) { set $8g_block 1; }

# Allow safe headless browsers, block aggressive scrapers
if ($http_user_agent ~* "(headlesschrome|puppeteer|casperjs|scrapy)" ) { set $8g_block 1; }

# Allow regular browsers while blocking spoofed, outdated, or clearly malicious agents
if ($http_user_agent ~* "(MSIE 6\.0|Windows XP|Mozilla/4\.0 \(compatible; MSIE)" ) { set $8g_block 1; }

# 8G:[REFERRER]
# Block spammy referrers but allow legitimate search engine traffic
if ($http_referer ~* "(viagra|cialis|poker|pharma|lotto|shortener|bit\.ly)" ) { set $8g_block 1; }

# 8G:[POST]
# Block SQL injection in POST requests (focus on risky patterns, allow benign SQL)
if ($request_body ~* "(union.*select|insert.*into|drop table|delete from|alter table|xp_cmdshell|updatexml\()" ) { set $8g_post_block 1; }

# Allow benign POST requests but block XSS injections
if ($request_body ~* "(<script|javascript:|onerror|onload|onmouseover|style=expression\()" ) { set $8g_post_block 1; }

# Block dangerous file uploads and multipart abuse
if ($request_body ~* "(php://input|base64_decode|data:text)" ) { set $8g_post_block 1; }

if ($8g_post_block = 1) {
    set $8g_block 1;
}

# Block requests if any of the above rules are matched
if ($8g_block = 1) {
    access_log /var/log/nginx/blocked.log;
    return 403;
}

# Add security headers for better protection
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer-when-downgrade" always;
add_header Content-Security-Policy "default-src 'self' http: https: data: blob: 'unsafe-inline'" always;

# Force HTTPS redirection (allow access to non-sensitive pages over HTTP)
if ($scheme != "https") {
    return 301 https://$server_name$request_uri;
}

# File upload restrictions (adjust as needed)
client_max_body_size 10M;

# Prevent access to sensitive files and system paths
location ~* "(\.htaccess|\.htpasswd|\.ini|\.log|\.sh|wp-config\.php|\.bak|\.swp|\.pem|\.crt|\.key)$" { deny all; }

# Block access to specific sensitive file extensions
location ~* \.(bak|config|sql|ini|log|sh|swp|dist|old|mdb|json|pem|crt|key|pfx|csr|conf)$ {
    deny all;
}

# Block access to hidden files and directories
location ~ /\. {
    deny all;
}

# Rate limiting for specific endpoints to prevent brute force attacks
location /wp-login.php {
    limit_req zone=8g_limit burst=5 nodelay;
}

location /xmlrpc.php {
    limit_req zone=8g_limit burst=5 nodelay;
}

# Restrict access to sensitive directories and admin panels
location ~* /(admin|mysqladmin|phpmyadmin|pma|myadmin|debug\.php)$ {
    deny all;
}
