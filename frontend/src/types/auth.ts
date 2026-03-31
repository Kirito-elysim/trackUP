export type Feature = {
  id: number;
  code: string;
  name: string;
  category: string;
  description: string | null;
};

export type Role = {
  id: number;
  code: string;
  name: string;
  description: string | null;
  system: boolean;
  featureCodes: string[];
};

export type UserSummary = {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  active: boolean;
  roles: Array<{
    id: number;
    code: string;
    name: string;
  }>;
};

export type AuthenticatedUser = {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  fullName: string;
  isAdmin: boolean;
  roles: Array<{
    id: number;
    code: string;
    name: string;
  }>;
  features: string[];
};
