// Miroir côté client des règles de backend/src/Validation/ContactInfoValidator.php — permet de
// calculer toutes les erreurs d'un formulaire en une fois (sans aller-retour serveur) pour les
// afficher simultanément sous chaque champ, plutôt qu'un message à la fois après chaque soumission.
export function isValidEmail(email: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export function isValidPhone(phone: string): boolean {
  const normalized = phone.replace(/[\s.-]/g, '');
  return /^(0[1-9]\d{8}|\+33[1-9]\d{8})$/.test(normalized);
}

export function isValidPostalCode(postalCode: string): boolean {
  return /^\d{5}$/.test(postalCode);
}
