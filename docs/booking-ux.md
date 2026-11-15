# CutTime — dopracowanie procesu rezerwacji

## Znalezione problemy i poprawki

- Pasek etapów pomijał usługę i podsumowanie. Wspólny widok pokazuje teraz sześć kroków: **Usługa → Pracownik → Data → Godzina → Podsumowanie → Potwierdzenie**. Bieżący krok ma `aria-current="step"` i opis „Krok X z 6”, poprzednie kroki są oznaczone jako wybrane. Tytuł strony odpowiada aktualnemu wyborowi.
- Informacje przed zapisem były rozproszone. Podsumowanie ma usługę, pracownika, datę, wyraźnie zaznaczoną godzinę, czas trwania i aktualną cenę. Usunięto powtórzony opis/cenę/czas nad podsumowaniem, aby skrócić ekran telefonu. Samo otwarcie podsumowania nie zapisuje wizyty.
- Po błędzie klient musiał zaczynać od oferty. Link powrotu zachowuje prawidłowo sformatowane identyfikatory i przyszłą datę, pomija godzinę i prowadzi wyłącznie do lokalnego GET, który od nowa weryfikuje wybór w bazie. Przy błędzie etapu GET zachowywane są tylko wcześniej poprawnie odczytane elementy. To nawigacja, nie autoryzacja ani zgoda na zapis.
- Po zapisie następowało przekierowanie na ogólną stronę klienta. Obecnie 303 prowadzi do szczegółów właśnie zapisanej, własnej rezerwacji wraz z komunikatem sukcesu.
- Wyliczanie godzin obcinało sekundy SQL TIME, podczas gdy zapis sprawdzał dokładny przedział. Poprawiono obliczenia: początek pracy 09:00:30 nie pozwala proponować startu 09:00; istniejąca wizyta kończąca się 11:00:30 blokuje 11:00. Granice dokładnie stykające się nadal są dozwolone.
- Etykiety wyboru pracownika były identyczne. Nazwy dostępne dla czytnika wskazują teraz konkretną osobę. Pole daty ma opis strefy czasu i dopuszczalnego zakresu, a klawiaturowy fokus odnośników/przycisków jest wyraźny.
- Na małym ekranie zmniejszono zagnieżdżone odstępy, pasek etapów układa się w dwie kolumny, podsumowanie i godziny dopasowują się do dostępnej szerokości.

Pokazywane są tylko wolne godziny obejmujące całą usługę. Zajęte i niedopuszczalne godziny nie mają aktywnych przycisków; komunikat wyjaśnia, dlaczego nie są oferowane. Pusty dzień ma informację o braku terminów i odnośnik do zmiany daty. Wyboru i potwierdzenia nie uzależniono od JavaScript.

## Kontrola parametrów i endpointów

| Parametr lub operacja | Źródło prawdy / ochrona |
| --- | --- |
| `service_id` | Poprawny identyfikator, aktywna usługa i kategoria z bazy; sprawdzane również w transakcji zapisu. |
| `employee_id` | Aktywny profil i konto pracownika, jego rola i relacja N:M z usługą; ponowny odczyt przed zapisem. |
| `date`, `time` | Ścisły format, przyszłość w Europe/Warsaw, siatka 15 minut, cały przedział w godzinach pracy, brak kolizji. |
| `duration`, `end_time` | Dane formularza nie sterują obliczeniami. Czas trwania pobierany ponownie z bazy, koniec obliczany przez PHP. |
| `price` | Oferta i podsumowanie pobierają aktualne `services.price`. Przesłana cena jest ignorowana; obecny schemat nie zapisuje ceny przy rezerwacji. |
| `user_id`, rola | ID właściciela z sesji przez istniejącą autoryzację. Publiczna oferta nie uprawnia do zapisu. |
| `status` nowej wizyty | Serwer ustala `pending`, niezależnie od żądania. |
| Anulowanie klienta | Tylko własna przyszła wizyta `pending`/`confirmed`, POST, CSRF, potwierdzenie, ponowne sprawdzenie pod blokadą. |
| Zmiana statusu pracownika | Profil z konta, własność w SQL, zamknięte przejścia, kontrola czasu i starego formularza, POST/CSRF. |

Przejrzano `/services.php`, `/service.php`, `/booking.php`, `/booking-save.php`, `/client-reservations.php` i `/employee.php` wraz ze wspólnymi funkcjami. Lista i szczegóły pozostają tylko do odczytu. Parametry z formularza nie zastępują ponownej kontroli w bazie. Pozostawiono prepared statements, XSS, blokadę pracownika, transakcję i sprawdzenie konfliktu bezpośrednio przed INSERT. Dwie równoczesne próby tego samego terminu nadal dają jeden zapis i jeden konflikt. Nie zmieniano schematu SQL ani administracyjnej korekty statusów.

## Testy

Pełny zestaw PHP 8.2.35 / MariaDB 11.4.5: **1472 zaliczone, 0 błędów**. Składnia wszystkich **91 plików PHP** poprawna. Nowe testy obejmują sześć kroków, komplet podsumowania, puste dni, powrót po błędzie, sekundy godzin pracy i konfliktów, zmianę czasu trwania po otwarciu podsumowania oraz zmodyfikowane żądania. Dotychczasowy zestaw nadal weryfikuje CSRF, role, własność, przejścia statusów, wszystkie wymagane kolizje i równoczesne zapisy.

Microsoft Edge w trybie headless wyrenderował **13 rzeczywistych odpowiedzi HTML aplikacji przy szerokościach 320, 375 i 768 px — 39 kontroli**. Sprawdzono brak poziomego przepełnienia względem szerokości widoku oraz etykiety pól formularzy. Obejmuje to ofertę, szczegóły usługi, pracownika, datę, godziny, podsumowanie, pusty dzień, błędy wyboru i zapisu, listę/szczegóły klienta i pracownika. Dodatkowo obejrzano zrzut podsumowania na telefonie. To test renderowania migawek; interakcje i mutacje są oddzielnie badane przez HTTP. Nie wykonywano testu na fizycznym telefonie ani z czytnikiem ekranu.

Opcjonalne powtórzenie testu układu:

1. Uruchomić istniejący zestaw `php tests/http.php` z testowym DSN oraz `CUTTIME_UI_DIR` wskazującym istniejący, nowy katalog `%TEMP%/cuttime-ux-<16 małych znaków hex>`.
2. Na Windows z Edge uruchomić `tests/booking-responsive.ps1 -SnapshotDirectory <katalog>`. Jeśli polityka skryptów tego wymaga, można użyć `powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests/booking-responsive.ps1 -SnapshotDirectory <katalog>`; odstępstwo dotyczy tylko tego procesu.
3. Raport `responsive-results.json` i podgląd `summary-mobile.png` powstają w katalogu testowym. Nie trafiają do repozytorium. Testy używają własnej losowej bazy, bez zmieniania bazy lokalnej aplikacji.

## Pliki

- `includes/booking.php` — dokładność sekund i bezpieczny lokalny link powrotu.
- `includes/booking-save.php` — czytelniejsza informacja o braku dostępności/przypisania.
- `includes/booking-progress-view.php`, `includes/booking-recovery-view.php` — nowe wspólne widoki kroków i powrotu.
- `includes/booking-selection-view.php`, `includes/offer-view.php`, `includes/offer-page.php`, `includes/account-view.php` — oznaczenie etapu, podsumowanie, etykiety i obsługa błędów.
- `public/booking-save.php` — przekierowanie do zapisanej wizyty i nawigacja po błędzie.
- `public/css/auth.css` — układ telefonu, wyróżnienie godziny i fokus.
- `tests/booking-ux-http.php`, `tests/booking-responsive.ps1` — nowe testy; `tests/booking-save-http.php`, `tests/http.php` — podłączenie i aktualizacja oczekiwanego przekierowania.
- `README.md`, `docs/client-booking.md`, `docs/booking-save.md`, `docs/booking-ux.md` — aktualna dokumentacja.
