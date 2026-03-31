export type DashboardMetrics = {
  learnersCount: number;
  activeLearnersCount: number;
  trainingsCount: number;
  trainingRegistrationsCount: number;
  sessionsCount: number;
  sessionRegistrationsCount: number;
  stepStatesCount: number;
  signedAttendancesCount: number;
  totalTrackedTime: number;
  averageProgress: number;
};

export type DashboardTraining = {
  id: number;
  externalId: number;
  title: string;
  state: string;
  learnersCount: number;
  totalTime: number;
  averageProgress: number;
};

export type DashboardLearner = {
  id: number;
  externalId: number;
  email: string;
  firstName: string;
  lastName: string;
  fullName: string;
  state: string;
  lastLoginAt: string | null;
  trainingCount: number;
  totalTime: number;
};

export type DashboardPayload = {
  metrics: DashboardMetrics;
  topTrainings: DashboardTraining[];
  recentLearners: DashboardLearner[];
  lastSyncAt: string | null;
};

export type LearnerSummary = {
  id: number;
  externalId: number;
  email: string;
  firstName: string;
  lastName: string;
  fullName: string;
  state: string;
  lastLoginAt: string | null;
  lastActivityAt: string | null;
  syncedAt: string;
  trainingCount: number;
  totalTime: number;
  averageProgress: number;
  sessionRegistrationCount: number;
};

export type LearnerDetail = {
  learner: {
    id: number;
    externalId: number;
    username: string | null;
    email: string;
    firstName: string;
    lastName: string;
    fullName: string;
    language: string | null;
    phoneNumber: string | null;
    timezone: string | null;
    riseUpRole: string | null;
    type: string | null;
    state: string;
    activatedAt: string | null;
    suspendedAt: string | null;
    lastLoginAt: string | null;
    lastActivityAt: string | null;
    riseUpCreatedAt: string | null;
    riseUpUpdatedAt: string | null;
    syncedAt: string;
    trainingCount: number;
    totalTime: number;
    averageProgress: number;
    sessionRegistrationCount: number;
    signedAttendanceCount: number;
  };
  trainingRegistrations: Array<{
    id: number;
    externalId: number;
    trainingId: number;
    trainingExternalId: number;
    trainingTitle: string;
    trainingState: string;
    state: string;
    totalTime: number;
    progress: number | null;
    score: number | null;
    subscribedAt: string | null;
    trainingEndAt: string | null;
  }>;
  sessionRegistrations: Array<{
    id: number;
    externalId: number;
    state: string;
    attended: boolean | null;
    eduDuration: number | null;
    subscribedAt: string | null;
    sessionId: number;
    sessionExternalId: number;
    sessionType: string | null;
    startAt: string | null;
    endAt: string | null;
    meetingUrl: string | null;
    trainingTitle: string | null;
    signedCount: number;
  }>;
  recentActivities: Array<{
    id: number;
    externalId: number;
    state: string;
    timeSpent: number | null;
    totalTime: number | null;
    score: number | null;
    activityAt: string | null;
    stepId: number;
    stepExternalId: number;
    stepTitle: string;
    stepType: string | null;
    moduleTitle: string;
    trainingTitle: string;
  }>;
};

export type TrainingSummary = {
  id: number;
  externalId: number;
  title: string;
  reference: string | null;
  state: string | null;
  type: string | null;
  eduDuration: number | null;
  language: string | null;
  syncedAt: string;
  learnersCount: number;
  totalTime: number;
  averageProgress: number;
  moduleCount: number;
  stepCount: number;
  sessionCount: number;
};

export type TrainingDetail = {
  training: {
    id: number;
    externalId: number;
    title: string;
    reference: string | null;
    description: string | null;
    language: string | null;
    objective: string | null;
    eduDuration: number | null;
    state: string | null;
    externalLink: string | null;
    type: string | null;
    sequential: boolean | null;
    riseUpCreatedAt: string | null;
    riseUpUpdatedAt: string | null;
    syncedAt: string;
    learnersCount: number;
    totalTime: number;
    averageProgress: number;
    averageScore: number;
    sessionCount: number;
  };
  modules: Array<{
    id: number;
    externalId: number;
    title: string;
    description: string | null;
    eduDuration: number | null;
    duration: number | null;
    type: string | null;
    reference: string | null;
    position: number | null;
    language: string | null;
    stepCount: number;
  }>;
  sessions: Array<{
    id: number;
    externalId: number;
    sessionType: string | null;
    state: string | null;
    startAt: string | null;
    endAt: string | null;
    location: string | null;
    room: string | null;
    meetingUrl: string | null;
    eduDuration: number | null;
    registrationCount: number;
    attendedCount: number;
  }>;
  topLearners: Array<{
    id: number;
    learnerId: number;
    email: string;
    firstName: string;
    lastName: string;
    fullName: string;
    state: string;
    totalTime: number;
    progress: number | null;
    score: number | null;
  }>;
};

export type ExportsPayload = {
  metrics: {
    learnersReadyCount: number;
    signedRegistrationsCount: number;
    sessionsWithoutSignatureCount: number;
    trackedTimeTotal: number;
  };
  learnerExports: Array<{
    id: number;
    email: string;
    firstName: string;
    lastName: string;
    fullName: string;
    trainingCount: number;
    totalTime: number;
    averageProgress: number;
    signedCount: number;
  }>;
  trainingExports: Array<{
    id: number;
    title: string;
    state: string | null;
    learnersCount: number;
    totalTime: number;
    averageProgress: number;
    sessionCount: number;
  }>;
  complianceAlerts: Array<{
    learnerId: number;
    email: string;
    firstName: string;
    lastName: string;
    fullName: string;
    trainingTitle: string | null;
    sessionStartAt: string | null;
    unsignedAttendances: number;
  }>;
};

export type IntegrationsPayload = {
  connection: {
    provider: string;
    mode: string;
    apiBaseUrl: string;
    health: string;
    lastSyncAt: string | null;
  };
  metrics: {
    datasetsCount: number;
    syncedDatasetsCount: number;
    totalRows: number;
  };
  datasets: Array<{
    label: string;
    table: string;
    primaryCount: number;
    secondaryCount: number | null;
    lastSyncAt: string | null;
    command: string;
    status: string;
  }>;
  commands: string[];
};
