import { isValidEmail, isValidPhone, isValidPostalCode } from './validation';

export type TutorFormValues = {
  firstName: string;
  lastName: string;
  email: string;
  phoneMobile: string;
  phoneFixe: string;
  postalCode: string;
};

// Calcule toutes les erreurs du formulaire Tuteur en une fois (création et édition partagent les
// mêmes règles) — l'email est obligatoire car c'est la clef d'identification du tuteur.
export function validateTutorForm(values: TutorFormValues): Record<string, string> {
  const errors: Record<string, string> = {};

  if (values.firstName.trim() === '') {
    errors.firstName = 'Ce champ est obligatoire.';
  }

  if (values.lastName.trim() === '') {
    errors.lastName = 'Ce champ est obligatoire.';
  }

  if (values.email.trim() === '') {
    errors.email = 'Ce champ est obligatoire.';
  } else if (!isValidEmail(values.email.trim())) {
    errors.email = "L'email n'est pas valide.";
  }

  if (values.phoneMobile.trim() !== '' && !isValidPhone(values.phoneMobile.trim())) {
    errors.phoneMobile = "Le numéro de téléphone n'est pas valide.";
  }

  if (values.phoneFixe.trim() !== '' && !isValidPhone(values.phoneFixe.trim())) {
    errors.phoneFixe = "Le numéro de téléphone n'est pas valide.";
  }

  if (values.postalCode.trim() !== '' && !isValidPostalCode(values.postalCode.trim())) {
    errors.postalCode = 'Le code postal doit contenir exactement 5 chiffres.';
  }

  return errors;
}

export function mapTutorServerError(message: string): string | null {
  if (message.includes('email')) {
    return 'email';
  }
  if (message.includes('prénom')) {
    return 'firstName';
  }
  if (message.includes('nom')) {
    return 'lastName';
  }
  if (message.includes('code postal')) {
    return 'postalCode';
  }
  if (message.includes('téléphone')) {
    return 'phoneMobile';
  }
  return null;
}
