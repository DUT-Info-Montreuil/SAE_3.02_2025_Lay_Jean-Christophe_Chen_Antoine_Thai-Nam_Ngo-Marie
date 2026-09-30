#!/bin/sh
# Lance tous les tests de détection. Code de sortie = nombre de scripts contenant au moins une faille.
cd "$(dirname "$0")/../.." || exit 2
ko=0
for t in tests/securite/test_*.php; do
  echo "=================== $t"
  php -d display_errors=0 "$t" || ko=$((ko+1))
done
echo; echo "$ko script(s) signalent des failles"
exit $ko
