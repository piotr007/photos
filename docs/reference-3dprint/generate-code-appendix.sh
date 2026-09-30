#!/usr/bin/env bash
# Generates docs/ai-handover/10-kod-zrodlowy.md — a verbatim snapshot of the project's key source
# files, so the handover docs stay self-contained. Re-run after meaningful code changes:
#   ./docs/ai-handover/generate-code-appendix.sh
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$PROJECT_ROOT/docs/ai-handover/10-kod-zrodlowy.md"
cd "$PROJECT_ROOT"

# section title | fence language | files...
SECTIONS=(
  "Infrastruktura (Docker, nginx, skrypty)|yaml|docker-compose.yml"
  "Infrastruktura (Docker, nginx, skrypty)|dockerfile|docker/php/Dockerfile"
  "Infrastruktura (Docker, nginx, skrypty)|nginx|docker/nginx/default.conf"
  "Infrastruktura (Docker, nginx, skrypty)|bash|scripts/db.sh zip-project.sh deploy-package.sh"
  "Konfiguracja projektu|json|composer.json package.json"
  "Konfiguracja projektu|js|vite.config.js"
  "Konfiguracja projektu|xml|phpunit.xml"
  "Konfiguracja projektu|ini|.env.example .env.docker.example"
  "Konfiguracja projektu|gitignore|.gitignore"
  "Bootstrap i providery|php|bootstrap/app.php bootstrap/providers.php app/Providers/AppServiceProvider.php app/Providers/Filament/AdminPanelProvider.php"
  "Modele Eloquent|php|app/Models/Project.php app/Models/ProjectImage.php app/Models/ProjectFile.php app/Models/User.php"
  "Observery i helper mediów|php|app/Observers/ProjectImageObserver.php app/Observers/ProjectFileObserver.php app/Support/ProjectMedia.php"
  "Panel Filament|php|app/Filament/Admin/Resources/ProjectResource.php app/Filament/Admin/Resources/ProjectResource/Pages/ListProjects.php app/Filament/Admin/Resources/ProjectResource/Pages/CreateProject.php app/Filament/Admin/Resources/ProjectResource/Pages/EditProject.php app/Filament/Admin/Resources/ProjectResource/RelationManagers/ImagesRelationManager.php app/Filament/Admin/Resources/ProjectResource/RelationManagers/FilesRelationManager.php"
  "Panel Filament|blade|resources/views/filament/project-image-preview.blade.php"
  "Routing i kontrolery|php|routes/web.php routes/console.php app/Http/Controllers/Controller.php app/Http/Controllers/HomeController.php app/Http/Controllers/ProjectController.php"
  "Widoki publiczne (Blade)|blade|resources/views/layouts/app.blade.php resources/views/home.blade.php resources/views/projects/index.blade.php resources/views/projects/show.blade.php"
  "Assety front|css|resources/css/app.css"
  "Assety front|js|resources/js/app.js resources/js/glightbox.js"
  "Migracje|php|database/migrations/0001_01_01_000000_create_users_table.php database/migrations/2026_08_29_213304_create_projects_table.php database/migrations/2026_08_29_213305_create_project_images_table.php database/migrations/2026_08_29_213306_create_project_files_table.php database/migrations/2026_08_30_225700_add_is_featured_to_project_images_table.php database/migrations/2026_08_31_202500_add_two_factor_columns_to_users_table.php"
  "Seeder i fabryki|php|database/seeders/DatabaseSeeder.php database/factories/UserFactory.php"
  "Testy|php|tests/TestCase.php tests/Feature/AdminAuthTest.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php"
)

{
  echo "# 10 — Kod źródłowy (snapshot kluczowych plików)"
  echo
  echo "> Wygenerowano automatycznie: \`$(date '+%Y-%m-%d %H:%M')\`, commit \`$(git rev-parse --short HEAD 2>/dev/null || echo n/a)\`"
  echo "> skryptem \`docs/ai-handover/generate-code-appendix.sh\`. **Nie edytuj ręcznie** — uruchom skrypt ponownie."
  echo ">"
  echo "> Pliki \`config/*.php\` to niezmienione defaulty Laravel 13 (pominięte). Sekrety: \`.env\` nie jest dołączony (tylko szablony)."
  echo
  echo "## Spis treści"
  echo
  prev=""
  for s in "${SECTIONS[@]}"; do
    title="${s%%|*}"
    if [[ "$title" != "$prev" ]]; then
      anchor="$(echo "$title" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9ąćęłńóśźż]+/-/g; s/^-|-$//g')"
      echo "- [$title](#$anchor)"
      prev="$title"
    fi
  done
  echo
  prev=""
  for s in "${SECTIONS[@]}"; do
    title="${s%%|*}"; rest="${s#*|}"; lang="${rest%%|*}"; files="${rest#*|}"
    if [[ "$title" != "$prev" ]]; then
      echo "---"; echo; echo "## $title"; echo
      prev="$title"
    fi
    for f in $files; do
      if [[ ! -f "$f" ]]; then echo "### \`$f\`"; echo; echo "_(brak pliku)_"; echo; continue; fi
      echo "### \`$f\`"
      echo
      echo '```'"$lang"
      cat "$f"
      [[ -n "$(tail -c1 "$f")" ]] && echo
      echo '```'
      echo
    done
  done
} > "$OUT"

echo "Written: $OUT ($(wc -l < "$OUT") lines)"
