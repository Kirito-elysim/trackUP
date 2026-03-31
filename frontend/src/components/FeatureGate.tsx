import type { ReactElement } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';

type FeatureGateProps = {
  feature: string;
  children: ReactElement;
};

export function FeatureGate({ feature, children }: FeatureGateProps) {
  const { canAccess } = useAuth();

  if (!canAccess(feature)) {
    return <Navigate replace to="/dashboard" />;
  }

  return children;
}
