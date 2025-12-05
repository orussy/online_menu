<?php
/**
 * Configuration file for Foodics API
 * Update the token here when you get a new one from your Foodics account
 * 
 * To get a new token:
 * 1. Log in to your Foodics account
 * 2. Go to API settings/integrations
 * 3. Generate a new Bearer token
 * 4. Copy the token and paste it below (without quotes)
 */

// Prevent direct access
if (!defined('ALLOW_INCLUDE')) {
    http_response_code(403);
    die('Direct access not allowed');
}

return [
    'api_base_url' => 'https://api.foodics.com/v5/',
    // TODO: Replace with your valid Bearer token from Foodics
    // The current token appears to be expired or invalid
    'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI5MGQ1YTcxOC1lMzBkLTQ5ODYtODY0Ni0wNjdlZDBkMzdkMGUiLCJqdGkiOiI0ZTk0MWU0M2I1YWE3N2IzNzkyYzEzYmQzYjUzMzNmZGZjYTdjNDJhNDc0NDdlMjE3MGFhZGQ1MDNlYTQzYmViNmZmNjk0NWY3MjM5MTYzYSIsImlhdCI6MTc1NTQzMzcxMi4wNTEwOTUsIm5iZiI6MTc1NTQzMzcxMi4wNTEwOTUsImV4cCI6MTkxMzIwMDExMi4wMjc2NDgsInN1YiI6Ijk5NDgyYTQyLWU0ZWQtNDk1My1hOTNjLTc5YTVmMDQzYmVmYyIsInNjb3BlcyI6WyJnZW5lcmFsLnJlYWQiLCJvcmRlcnMubGlzdCIsIm9wZXJhdGlvbnMucmVhZCIsIm1lbnUuaW5ncmVkaWVudHMucmVhZCIsImludmVudG9yeS50cmFuc2FjdGlvbnMucmVhZCIsImludmVudG9yeS50cmFuc2FjdGlvbnMud3JpdGUiLCJpbnZlbnRvcnkuc2V0dGluZ3MucmVhZCIsImludmVudG9yeS5zZXR0aW5ncy53cml0ZSJdLCJidXNpbmVzcyI6Ijk5NDgyYTQyLWZlMmEtNDdmZi1hMDJkLTNhZjZlZDlmMzg2ZCIsInJlZmVyZW5jZSI6IjQ0ODI5NSJ9.kXW8DdPFYrn4CgenKeKqUO1tsgtXg7F4lIfHC6fx_TXt0SCq27cs45HiPY132zmtF2QQGmx_8bjTSnknQtZbL4SuKTeIUojM7AxxErkQWuJ8kYiUWCPUdIOUuEEpX-oJi_-DWUojfV7hkK2T9PAXDRzk65CSF3JzYCzHKkwlvlHl2IJ4r_63I4PbU689aUe6wbKDiPCgHRUFAh91nrW0b4Lr7dZq8kMAHOKs2y2lumPyOEojTR7IVAYeMf-K_-Smx67F7KI-gHXarQ9lJ93Ce7tLg_UDINh7mFGKT92oZR3ljGdDzdsbZzg4B89T1zyaOmyHVO5bzeltPZDH2uWVjXqPwa-3OfNyj5RIITkjtHb9KGnDhdANUKpja_bn5YG2p3lu2XSUhvAlBdhNsHrsHMHTkaDgJ9fNkcCzfnJ1gtlwXj9EQHUu9dsI2Kl3O6oKrSmRfFGS2X9Hh1e2GyOQIZFAyBNi8A5e1MmWYyvB3MJd3kp4uoU3PD-Ju88rjOImGLrj8HiYCKKi0XnKZW-xYtTzp-JMZi4C4i1onuxMUte-xliw-3_bTJhg5hEG4GEMeNuZ23sYth_wnCxXHaHTWsxsuIaMdHYyurK-nBlNfdDK9XwVdPy08l1A99pOqNXOsR0PaLYwZxxzFrk-X3zU2Woexj12hn5Bd0TwLLCEYI4'
];
