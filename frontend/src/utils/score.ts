export const formatScore = (score: number | null | undefined): string => {
  if (score === null || score === undefined) {
    return "N/A";
  }

  return score.toFixed(1);
};

export const scoreClass = (score: number | null | undefined): string => {
  if (score === null || score === undefined) {
    return "score neutral";
  }

  if (score >= 8) {
    return "score good";
  }

  if (score >= 6.5) {
    return "score watch";
  }

  return "score risk";
};

export const riskClass = (severity: string): string => {
  if (severity === "high" || severity === "critical") {
    return "risk high";
  }

  if (severity === "medium") {
    return "risk medium";
  }

  return "risk low";
};
