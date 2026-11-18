# ============================================================================
# PROSIT — servizi, schede e API sotto /lizmap/prosit
# ----------------------------------------------------------------------------
# Va incluso DENTRO ogni <VirtualHost> che serve Lizmap (http e https),
# PRIMA di qualsiasi "Alias /lizmap ...":
#
#     <VirtualHost *:80>
#         Include /opt/prosit/deploy/apache/prosit.inc
#         ...
#
# Perche' sotto /lizmap/: il cookie di sessione di Lizmap (Jelix) vale solo per
# il percorso /lizmap/, quindi solo li' il browser lo invia anche a Prosit.
# Lo fa install.sh (con copia di sicurezza e verifica). Richiede mod_headers.
# ============================================================================

Alias /lizmap/prosit /opt/prosit/public

<Directory /opt/prosit/public>
    Options -Indexes -MultiViews
    AllowOverride None
    Require all granted
    DirectoryIndex index.php index.html

    # file nascosti (.user.ini) e include interni (_form.php) non si servono mai
    <FilesMatch "^[._]">
        Require all denied
    </FilesMatch>
</Directory>

# API: un solo punto di ingresso per /lizmap/prosit/api/v1/...
<Directory /opt/prosit/public/api>
    FallbackResource /lizmap/prosit/api/index.php
</Directory>

# QField: upload grandi (con PHP-FPM valgono i limiti in hooks/qfield/.user.ini)
<Directory /opt/prosit/public/hooks/qfield>
    <IfModule mod_php.c>
        php_value upload_max_filesize 100M
        php_value post_max_size 120M
        php_value memory_limit 256M
        php_value max_execution_time 300
    </IfModule>
</Directory>

<IfModule mod_headers.c>
    <Location /lizmap/prosit>
        Header always set X-Content-Type-Options "nosniff"
        Header always set Referrer-Policy "same-origin"
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    </Location>
    # schede popup: nessuno script o stile esterno o in linea
    <Location /lizmap/prosit/schede>
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'"
    </Location>
    # applicazioni (Bootstrap Italia + codice esistente con stili/script in linea)
    <Location /lizmap/prosit/app>
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'"
    </Location>
    # risorse statiche versionate: cache lunga
    <LocationMatch "^/lizmap/prosit/assets/vendor/">
        Header set Cache-Control "public, max-age=2592000, immutable"
    </LocationMatch>
</IfModule>

# Indirizzi vecchi (redirect e alias): file separato, si toglie quando non servono piu'
IncludeOptional /opt/prosit/deploy/apache/prosit-legacy.inc
