export type DashboardMetrics = {
  learnersCount: number;
  activeLearnersCount: number;
  learningPathsCount: number;
  trainingsCount: number;
  trainingRegistrationsCount: number;
  sessionsCount: number;
  masterclassCount: number;
  sessionRegistrationsCount: number;
  stepStatesCount: number;
  signedAttendancesCount: number;
  totalTrackedTime: number;
  totalYearTime: number;
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

export type DashboardLearningPath = {
  id: number;
  externalId: number;
  title: string;
  imageUrl: string | null;
  description: string | null;
  learnerCount: number;
  averageProgress: number;
  totalTime: number;
  trainingCount: number;
  masterclassCount: number;
};

export type LearnerSession = {
  id: number;
  externalId: number;
  title: string;
  sessionType: string;
  startAt: string | null;
  endAt: string | null;
  eduDuration: number | null;
  registrationId: number;
  attended: boolean | null;
  learnerEduDuration: number | null;
  hasSigned: boolean;
  attendanceDate: string | null;
  signatureDate: string | null;
  trainingId: number;
  trainingTitle: string;
  learningPathTitle?: string;
  countedTime: number;
  isFuture: boolean;
};

export type LearnerSessionsPayload = {
  sessions: LearnerSession[];
};

export type DashboardPayload = {
  metrics: DashboardMetrics;
  groups: GroupSummary[];
  topTrainings: DashboardTraining[];
  recentLearners: DashboardLearner[];
  lastSyncAt: string | null;
};

export type AnalyticsPayload = {
  filters: {
    period: string;
    startAt: string;
    endAt: string;
    previousStartAt: string;
    previousEndAt: string;
    bucket: string;
    learningPathId: number | null;
    learnerId: number | null;
    availableLearningPaths: Array<{
      id: number;
      title: string;
    }>;
    availableLearners: Array<{
      id: number;
      fullName: string;
    }>;
  };
  metrics: {
    totalTrackedTime: number;
    elearningTrackedTime: number;
    masterclassTrackedTime: number;
    activeLearnersCount: number;
    learningPathsCount: number;
    activityCount: number;
    averageProgress: number;
  };
  comparison: {
    totalTrackedTime: ComparisonMetric;
    elearningTrackedTime: ComparisonMetric;
    masterclassTrackedTime: ComparisonMetric;
    activeLearnersCount: ComparisonMetric;
    activityCount: ComparisonMetric;
  };
  timeSeries: Array<{
    bucketKey: string;
    label: string;
    totalTime: number;
    elearningTime: number;
    masterclassTime: number;
    activityCount: number;
    activeLearnersCount: number;
  }>;
  topLearningPaths: Array<{
    id: number;
    title: string;
    learnerCount: number;
    averageProgress: number;
    targetDuration: number;
    totalTime: number;
    elearningTime: number;
    masterclassTime: number;
    timeProgressPercent: number;
  }>;
  learnerPathRows: Array<{
    learnerId: number;
    learningPathId: number;
    learningPathTitle: string;
    email: string | null;
    firstName: string | null;
    lastName: string | null;
    fullName: string;
    lastLoginAt: string | null;
    learningPathProgressPercent: number;
    trainingProgressPercent: number;
    pathTargetDuration: number;
    totalTime: number;
    elearningTime: number;
    masterclassTime: number;
    activityCount: number;
    lastActivityAt: string | null;
    timeProgressPercent: number;
  }>;
  trainingRows: Array<{
    trainingId: number;
    title: string;
    learnerCount: number;
    targetDuration: number;
    averageProgress: number;
    totalTime: number;
    elearningTime: number;
    masterclassTime: number;
    timeProgressPercent: number;
  }>;
  lastSyncAt: string | null;
};

export type ComparisonMetric = {
  current: number;
  previous: number;
  delta: number;
  percentDelta: number;
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
    groupId: number | null;
    groupName: string | null;
    groupTotalTime: number;
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
  filters: {
    learnerId: number | null;
    availableLearners: Array<{
      id: number;
      email: string;
      fullName: string;
    }>;
  };
  metrics: {
    learnersReadyCount: number;
    learningPathsCount: number;
    signedRegistrationsCount: number;
    sessionsWithoutSignatureCount: number;
    trackedTimeTotal: number;
  };
  selectedLearner: {
    learner: {
      id: number;
      email: string;
      firstName: string | null;
      lastName: string | null;
      fullName: string;
      state: string;
      lastLoginAt: string | null;
      lastActivityAt: string | null;
      trainingCount: number;
      learningPathCount: number;
      platformTime: number;
      moduleTime: number;
      masterclassTime: number;
      signedAttendanceCount: number;
      unsignedAttendanceCount: number;
    };
    learningPaths: Array<{
      learningPathId: number;
      title: string;
      reference: string | null;
      subscribedAt: string | null;
      progress: number | null;
      score: number | null;
      trainingCount: number;
      platformTime: number;
      moduleTime: number;
      masterclassTime: number;
    }>;
    trainings: Array<{
      trainingId: number;
      title: string;
      state: string | null;
      subscribedAt: string | null;
      platformTime: number;
      progress: number | null;
      score: number | null;
      learningPathTitles: string[];
      moduleTime: number;
      masterclassTime: number;
    }>;
    logs: Array<{
      occurredAt: string | null;
      sourceType: string;
      sourceLabel: string | null;
      learningPathTitle: string | null;
      trainingTitle: string | null;
      moduleTitle: string | null;
      stepTitle: string | null;
      sessionType: string | null;
      duration: number;
      status: string | null;
      signed: boolean | null;
      details: string | null;
    }>;
  } | null;
};

export type RiseUpActivityLogsPayload = {
  filters: {
    learnerQuery: string | null;
    groupExternalId: number | null;
    learningPathId: number | null;
    trainingExternalId: number | null;
    dateFrom: string | null;
    dateTo: string | null;
    availableGroups: Array<{
      externalId: number;
      name: string;
    }>;
    availableLearningPaths: Array<{
      id: number;
      title: string;
    }>;
    availableTrainings: Array<{
      externalId: number;
      title: string;
    }>;
  };
  pagination: {
    page: number;
    pageSize: number;
    totalRows: number;
    totalPages: number;
  };
  metrics: {
    logCount: number;
    uniqueLearnersCount: number;
    uniqueTrainingsCount: number;
    totalDurationSeconds: number;
    totalDurationMinutes: number;
  };
  rows: Array<{
    id: number | string;
    sourceFileName: string;
    sourceImportedAt: string | null;
    trainingExternalId: number;
    trainingTitle: string;
    learnerExternalId: number | null;
    learnerEmail: string | null;
    learnerFullName: string;
    loginAt: string | null;
    logoutAt: string | null;
    durationSeconds: number;
    durationMinutes: number;
    device: string | null;
    createdAt: string | null;
    sourceType: string;
  }>;
  groupContext: {
    externalId: number;
    name: string;
    memberCount: number;
    learningPaths: Array<{
      id: number;
      title: string;
      learnerCount: number;
    }>;
  } | null;
  lastImportAt: string | null;
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

export type GroupSummary = {
  id: number;
  externalId: number;
  name: string;
  reference: string | null;
  imageUrl: string | null;
  memberCount: number;
  learningPathCount: number;
  totalTime: number;
  averageProgress: number;
};

export type GroupMember = {
  learnerId: number;
  email: string | null;
  firstName: string | null;
  lastName: string | null;
  fullName: string;
  joinedAt: string | null;
  elearningTime: number;
  sessionTime: number;
  totalTime: number;
  expectedTime: number;
  expectedElearningTime: number;
};

export type GroupDetail = {
  group: {
    id: number;
    externalId: number;
    name: string;
    reference: string | null;
    imageUrl: string | null;
    memberCount: number;
    learningPathCount: number;
    totalTime: number;
    averageProgress: number;
  };
  learningPaths: Array<{
    id: number;
    externalId: number;
    title: string;
    imageUrl: string | null;
  }>;
  members: GroupMember[];
};

export type LearningPathSummary = {
  id: number;
  externalId: number;
  title: string;
  reference: string | null;
  language: string | null;
  description: string | null;
  sequential: boolean | null;
  imageUrl: string | null;
  updatedAt: string;
  syncedAt: string;
  trainingCount: number;
  learnerCount: number;
  totalTime: number;
  averageProgress: number;
};

export type LearningPathDetail = {
  learningPath: {
    id: number;
    externalId: number;
    title: string;
    reference: string | null;
    language: string | null;
    description: string | null;
    sequential: boolean | null;
    imageUrl: string | null;
    riseUpCreatedAt: string | null;
    riseUpUpdatedAt: string | null;
    syncedAt: string;
    trainingCount: number;
    learnerCount: number;
    totalTime: number;
    averageProgress: number;
  };
  trainings: Array<{
    id: number;
    position: number | null;
    isRequired: boolean;
    trainingId: number;
    trainingExternalId: number;
    title: string;
    state: string | null;
    type: string | null;
    eduDuration: number | null;
    learnerCount: number;
    totalTime: number;
    averageProgress: number;
  }>;
  learners: Array<{
    id: number;
    reference: string | null;
    score: number | null;
    progress: number | null;
    subscribedAt: string | null;
    learnerId: number;
    email: string | null;
    firstName: string | null;
    lastName: string | null;
    fullName: string;
    completedTrainingCount: number;
    totalTime: number;
    sessionTime: number;
    expectedTime: number;
    elearningTime: number;
    expectedElearningTime: number;
    averageProgress: number;
  }>;
};
