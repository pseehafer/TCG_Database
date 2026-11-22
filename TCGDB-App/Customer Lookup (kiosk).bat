@echo off
rem Opens the Customer Lookup page full-screen for customers. TCG Database must be running.
rem Edge "public browsing" kiosk mode uses a private session, so the staff sign-in in your normal
rem browser is NOT shared with the customer screen, and customers cannot type other addresses.
rem To leave kiosk mode: press Alt+F4.
rem
rem Second monitor on this computer: change 1920 below to the width of your main screen.
rem Separate computer: copy this file to it and replace localhost with this computer's name or address
rem (icon by the clock > Addresses for other devices).

start "" msedge --kiosk "http://localhost:8080/kiosk.php" --edge-kiosk-type=public-browsing --no-first-run --window-position=1920,0
