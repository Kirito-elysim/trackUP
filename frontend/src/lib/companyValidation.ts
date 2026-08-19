import { isValidEmail, isValidPhone, isValidPostalCode } from './validation';

export type CompanyFormValues = {
  name: string;
  postalCode: string;
  contactEmail: string;
  contactPhoneMobile: string;
  contactPhoneFixe: string;
};

// Calcule toutes les erreurs du formulaire Entreprise en une fois (création et édition partagent
// les mêmes règles), pour les afficher simultanément sous chaque champ.
export function validateCompanyForm(values: CompanyFormValues): Record<string, string> {
  const errors: Record<string, string> = {};

  if (values.name.trim() === '') {
    errors.name = 'Ce champ est obligatoire.';
  }

  if (values.contactEmail.trim() !== '' && !isValidEmail(values.contactEmail.trim())) {
    errors.contactEmail = "L'email n'est pas valide.";
  }

  if (values.contactPhoneMobile.trim() !== '' && !isValidPhone(values.contactPhoneMobile.trim())) {
    errors.contactPhoneMobile = "Le numéro de téléphone n'est pas valide.";
  }

  if (values.contactPhoneFixe.trim() !== '' && !isValidPhone(values.contactPhoneFixe.trim())) {
    errors.contactPhoneFixe = "Le numéro de téléphone n'est pas valide.";
  }

  if (values.postalCode.trim() !== '' && !isValidPostalCode(values.postalCode.trim())) {
    errors.postalCode = 'Le code postal doit contenir exactement 5 chiffres.';
  }

  return errors;
}

// Les erreurs qui ne peuvent être détectées que côté serveur (unicité du SIRET) sont rattachées au
// bon champ plutôt que de rester un message générique en bas de formulaire.
export function mapCompanyServerError(message: string): string | null {
  if (message.includes('SIRET')) {
    return 'siret';
  }
  if (message.includes('nom')) {
    return 'name';
  }
  if (message.includes('code postal')) {
    return 'postalCode';
  }
  if (message.includes('email')) {
    return 'contactEmail';
  }
  if (message.includes('téléphone')) {
    return 'contactPhoneMobile';
  }
  return null;
}
