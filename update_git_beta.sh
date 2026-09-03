#!/bin/bash

echo "========================================="
echo "   Initialisation & Push LandroidRTK     "
echo "========================================="

# 1. S'assurer qu'on est bien sur la branche beta
git checkout beta || git checkout -b beta

# 2. Récupérer les modifications distantes si besoin
git pull origin beta

# 3. Demander le message de commit
echo "Entrez votre message de commit :"
read msg

# 4. Ajouter tous les fichiers modifiés
git add .

# 5. Créer le commit sur la branche beta
git commit -m "$msg"

# 6. Envoyer proprement sur la branche beta de GitHub
git push origin beta

echo "========================================="
echo "      Mise à jour terminée avec succès ! "
echo "========================================="
