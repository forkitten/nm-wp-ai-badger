# Spezifikation: WordPress-Plugin zur KI-Bildkennzeichnung

## 1. Ziel

Ein wiederverwendbares WordPress-Plugin, das Bilder in der Mediathek als „KI-generiert" oder „KI-unterstützt" markierbar macht und diese Kennzeichnung automatisch, serverseitig, als sichtbares Badge im Frontend anzeigt – unabhängig davon, ob das Bild über Gutenberg oder Etch ausgegeben wird. Zweck: Erfüllung der KI-Kennzeichnungspflicht auf ca. 150 Kunden-Installationen.

Plugin-Name (Vorschlag, code-intern Englisch): `NM AI Image Label`
Text-Domain (Vorschlag): `nm-ai-image-label`
Meta-Key: `_nm_ai_label`
Options-Key: `nm_ai_image_label_settings`

## 2. Datenmodell

Attachment-Post-Meta `_nm_ai_label`, mögliche Werte:

| Wert (intern, stabil) | Bedeutung |
|---|---|
| `''` (leer/nicht gesetzt) | Keine Kennzeichnung, kein Badge |
| `generated` | KI-generiert |
| `assisted` | KI-unterstützt |

Wichtig: Die internen Werte (`generated`/`assisted`) sind fest und code-seitig stabil. Die **angezeigten Texte** kommen ausschließlich aus den Settings (siehe 6.), damit spätere Textänderungen (z. B. Anpassung an neue rechtliche Formulierungen) keine Datenmigration erfordern.

## 3. Komponente: Feld in der Mediathek (ohne ACF-Abhängigkeit)

- Umsetzung nativ über `register_post_meta()` für Post-Type `attachment`.
- UI-Einbindung über den Filter `attachment_fields_to_edit` (deckt sowohl den klassischen „Medium bearbeiten"-Screen als auch das Attachment-Details-Panel im Medien-Modal ab – **ein** Hook für beide Oberflächen).
- Speicherung über `attachment_fields_to_save`.
- Feldtyp: Select/Dropdown mit drei Optionen: „Keine" / „KI-generiert" / „KI-unterstützt" (Label-Texte hier fix im Backend-UI, unabhängig von den Frontend-Badge-Texten aus den Settings).
- **Muss ohne ACF und ohne jede andere Plugin-Abhängigkeit funktionieren.**

## 4. Komponente: Serverseitiges Rendering (Badge-Injection)

Unterstützte Blocktypen von Anfang an:

- `core/image` (Gutenberg, für Blogbeiträge)
- `etch/dynamic-image` (Etch)

Umsetzung über `render_block_{$block_name}`-Filter (also `render_block_core/image` und `render_block_etch/dynamic-image`), beide leiten an eine gemeinsame Hilfsfunktion weiter, um Codeverdopplung zu vermeiden.

Ablauf der Hilfsfunktion `nm_ai_label_maybe_inject_badge( $block_content, $block )`:

1. Attachment-ID aus dem Block-Attribut lesen:
   - `core/image` → `$block['attrs']['id']`
   - `etch/dynamic-image` → `$block['attrs']['mediaId']`
2. Keine ID vorhanden → `$block_content` unverändert zurückgeben.
3. Prüfen, ob `$block_content` die Ausschluss-Klasse `nm-hide-ai-badge` enthält (String-Suche im HTML) → wenn ja, unverändert zurückgeben (Badge wird **komplett nicht gerendert**, nicht nur versteckt).
4. `get_post_meta( $media_id, '_nm_ai_label', true )` abfragen. Leer → unverändert zurückgeben.
5. Zugehörigen Badge-Text aus den Plugin-Settings holen (siehe 6.).
6. `$block_content` in einen eigenen, garantiert vorhandenen Wrapper packen (nicht abhängig davon, ob der Editor bereits ein `figure`-Element gesetzt hat):

   ```html
   <div class="nm-ai-badge-wrap">
     {ursprünglicher block_content}
     <span class="nm-ai-badge nm-ai-badge--{generated|assisted}">{Label-Text, escaped}</span>
   </div>
   ```

7. Modifizierten String zurückgeben.

Hinweis für die Umsetzung: Label-Text mit `esc_html()` ausgeben. Es soll keine Möglichkeit geben, dass über den Einstellungstext HTML/JS eingeschleust wird.

## 5. Komponente: Ausschluss-Mechanismus

- Reservierte CSS-Klasse: **`nm-hide-ai-badge`**
- Wird vom Editor/der Person, die die Seite baut, manuell auf den Block gesetzt:
  - **Gutenberg:** Block auswählen → Seitenleiste „Erweitert" → Feld „Zusätzliche CSS-Klasse(n)" → `nm-hide-ai-badge` eintragen.
  - **Etch:** Klasse direkt am Bild-Element (bzw. dem umgebenden Element, das im HTML landet) zuweisen, wie jede andere Utility-Klasse auch.
- Wirkung: Badge wird bei Vorhandensein dieser Klasse **serverseitig gar nicht erst ausgegeben** (kein `display:none`, sondern kein HTML-Output).
- Diese Prüfung muss so früh im Ablauf stattfinden (Schritt 3 oben), dass keine unnötige Meta-Abfrage mehr passiert, wenn die Klasse vorhanden ist.

## 6. Komponente: Settings-Seite

Menüpunkt unter „Einstellungen" (oder eigenes Top-Level-Menü, falls Claude Code das für sinnvoller hält – keine harte Vorgabe).

### Abschnitt „Badge-Texte"
Zwei Textfelder (per `register_setting`, gespeichert im Options-Array `nm_ai_image_label_settings`):
- `label_generated` – Default-Wert (englisch, siehe Abschnitt 8): „AI-generated"
- `label_assisted` – Default-Wert: „AI-assisted"

### Abschnitt „Badge ausblenden" (reine Dokumentation, kein Eingabefeld)
Kurzer Erklärtext mit genauer Anleitung, wie und wo die Klasse `nm-hide-ai-badge` gesetzt wird (Inhalt siehe Abschnitt 5, dort verständlich für Redakteur:innen aufbereitet, nicht nur für Entwickler:innen).

### Abschnitt „Styling"
- Auflistung der festen, überschreibbaren CSS-Klassen mit kurzer Erklärung, was sie steuern:
  - `.nm-ai-badge-wrap` – Wrapper um Bild + Badge, `position: relative`
  - `.nm-ai-badge` – Badge selbst (Basis-Styling: Farbe, Schrift, Padding, Position)
  - `.nm-ai-badge--generated` / `.nm-ai-badge--assisted` – für den Fall, dass später doch unterschiedliche Gestaltung pro Typ gewünscht ist (aktuell identisch gestaltet, wie besprochen)
- Freitext-Feld (Textarea) „Eigenes CSS" (`custom_css`), das eins zu eins in einem `<style>`-Tag im Frontend ausgegeben wird (z. B. im `wp_head`, ausreichend späte Priorität, damit es das Plugin-Default-CSS überschreiben kann). Kein Sanitizing über `wp_strip_all_tags` (würde CSS zerstören), lediglich Schutz gegen das Einschleusen eines schließenden `</style>`-Tags. Zugriff auf die Settings-Seite ohnehin nur für `manage_options`-Capability.

### Allgemeine Dokumentation auf der Settings-Seite
- Wo das Kennzeichnungs-Feld pro Bild gepflegt wird (Mediathek, siehe Abschnitt 3)
- Welche Blocktypen aktuell unterstützt werden (`core/image`, `etch/dynamic-image`)
- Hinweis, dass die Kennzeichnung nur bei Bildern greift, die über diese beiden Blocktypen eingebunden sind (siehe Abschnitt 9, „Out of Scope")

## 7. Default-CSS (Styling-Vorschlag, im Plugin enthalten)

Kleines, dezentes Badge, z. B. unten links über dem Bild positioniert, halbtransparenter dunkler Hintergrund, weißer Text, kleine Schrift, abgerundete Ecken. Genaue Werte sind Geschmackssache und können im Zuge der Umsetzung final designt werden – wichtig ist nur, dass die Selektoren aus Abschnitt 6 eingehalten werden, damit das Custom-CSS-Feld zuverlässig überschreiben kann (ausreichend niedrige Spezifität im Plugin-Default-CSS, z. B. eine Klassenebene, keine IDs, kein `!important`).

## 8. Internationalisierung

- Plugin-Standardsprache: **Englisch** (alle `__()`/`_e()`-Strings im Code auf Englisch).
- Deutsche Übersetzung als `.po`/`.mo`-Datei im `languages/`-Verzeichnis, Textdomain `nm-ai-image-label`, geladen über `load_plugin_textdomain()`.
- Betrifft: Backend-UI (Settings-Seite, Mediatheks-Dropdown-Feld, Dokumentationstexte). Die tatsächlichen Frontend-Badge-Texte sind ohnehin freie Eingabefelder (Abschnitt 6) und damit unabhängig vom i18n-System frei editierbar.

## 9. Unterstützte Blocktypen / Erweiterbarkeit / Out of Scope

**Im Scope:** `core/image`, `etch/dynamic-image`.

**Nicht im Scope (bewusst ausgeklammert, ggf. spätere Erweiterung):**
- Bilder als CSS-`background-image` (keine Attachment-ID im Markup verfügbar, bräuchte separaten Ansatz)
- Galerie-Blocks, Team-Bilder in Custom-Loops, ACF-Bildfelder außerhalb von Bild-Blöcken
- Andere Page-Builder (Beaver Builder, Bricks) – nicht Teil dieser Version

Empfehlung: Liste der unterstützten Blocktypen im Code als Filter/Konstante auslagern (z. B. `apply_filters( 'nm_ai_image_label_supported_blocks', [ 'core/image', 'etch/dynamic-image' ] )`), damit spätere Erweiterung ohne Eingriff in die Kernlogik möglich ist.

## 10. Offene technische Prüfpunkte während der Umsetzung

- Verifizieren, dass `$block['attrs']['mediaId']` bei `etch/dynamic-image` zuverlässig als String oder Int vorliegt (Type-Casting beim `get_post_meta()`-Aufruf beachten).
- Prüfen, ob der `<div class="nm-ai-badge-wrap">`-Wrapper in Kombination mit bestehendem Etch-eigenem `figure`-Wrapper (falls im Editor zusätzlich gesetzt) keine doppelten/verschachtelten Positionierungskontexte erzeugt, die das Badge falsch platzieren.
- Escaping/Sanitizing der Custom-CSS-Textarea nochmal gegenchecken (siehe Abschnitt 6).
