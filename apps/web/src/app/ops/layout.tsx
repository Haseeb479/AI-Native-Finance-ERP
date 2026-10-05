import { headers } from "next/headers";
import { notFound } from "next/navigation";
import { isOpsHostAllowed } from "@/lib/ops-auth";

export default async function OperationsHostLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const requestHeaders = await headers();
  if (!isOpsHostAllowed(requestHeaders.get("host"))) notFound();
  return children;
}
