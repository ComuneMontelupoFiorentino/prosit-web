# ============================================================================
# PROSIT — compatibilita' con gli indirizzi della vecchia VM (Lizmap 3.6.2)
# ----------------------------------------------------------------------------
# Pagine e schede: redirect TEMPORANEO (302) verso i nuovi indirizzi, la query
# string (?seg_id=..., ?id=...) viene mantenuta. Dopo il collaudo si puo' passare
# a 301. Quando i progetti QGIS puntano ai nuovi indirizzi (bin/prosit find-urls)
# questo file si puo' svuotare.
#
# Servizi chiamati da macchine (stazione meteo, app QField, sopralluoghi): ALIAS sullo
# stesso indirizzo (un redirect trasformerebbe i POST in GET).
# ============================================================================

# --- schede popup ---
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?rekla-info-sheet/schedaDisservizio\.html$"            "/lizmap/prosit/schede/disservizio.html"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?rekla-info-sheet/schedaDisservizioBit\.html$"         "/lizmap/prosit/schede/disservizio-bit.html"
RedirectMatch 302 "^/lizmap/(?:attivita-sul-territorio/)?manomissioni-info-sheet/manomissioni\.html$"      "/lizmap/prosit/schede/manomissioni.html"
RedirectMatch 302 "^/lizmap/(?:02-patrimonio-comunale-impianti/)?schedaPaliLuce\.html$"                    "/lizmap/prosit/schede/pali-luce.html"

# --- applicazioni ---
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?daily-log-tvcc(?:/(?:index\.php)?)?$"                 "/lizmap/prosit/app/registro-tvcc/"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?(?:calendar-green-works|vp-works-planned)(?:/(?:index\.(?:php|html))?)?$" "/lizmap/prosit/app/calendario-verde/"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?popup-ticket-data-report/rekla\.php$"                 "/lizmap/prosit/app/report-ticket/"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?popup-ticket-data-report/rekla_bit\.php$"             "/lizmap/prosit/app/report-ticket/bit.php"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?popup-ticket-data-report/report_timing\.php$"         "/lizmap/prosit/app/report-ticket/timing.php"
RedirectMatch 302 "^/lizmap/opendata/admin-service(?:/(?:services-admin\.php)?)?$"                          "/lizmap/prosit/app/opendata-admin/"
RedirectMatch 302 "^/lizmap/(?:gestione-disservizi/)?documents/document_proxy\.php$"                       "/lizmap/prosit/api/v1/documenti/rekla"
RedirectMatch 302 "^/lizmap/09-ambiente/legenda_incendi\.(?:PNG|png)$"                                     "/lizmap/prosit/static/ambiente/legenda_incendi.png"

# --- macchine: stesso indirizzo di prima ---
AliasMatch "^/lizmap/09-ambiente/get_data_station\.php$"                                                   "/opt/prosit/public/hooks/meteo/index.php"
AliasMatch "^/lizmap/ws-censimento-qfield(?:/(?:index\.php|health|profilo|upload|chiudi)?)?$"             "/opt/prosit/public/hooks/qfield/index.php"
AliasMatch "^/lizmap/attivita-sul-territorio/web-service-sopralluoghi/(open_todos|sync_on_open|sync_manomissioni_status)\.php$" "/opt/prosit/public/hooks/sopralluoghi/$1.php"

# La vecchia API (/lizmap/API/index.php/...) non c'e' piu': i dati sono sulla Prosit API v2
# (/lizmap/api.php/prosit/v2).
