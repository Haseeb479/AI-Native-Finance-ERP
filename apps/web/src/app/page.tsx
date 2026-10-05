import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { isDedicatedOpsHost } from "@/lib/ops-auth";
import FinovaLandingPage from "./website/page";

export default async function Page() {
  const requestHeaders = await headers();
  if (isDedicatedOpsHost(requestHeaders.get("host"))) {
    redirect("/ops/login");
  }

  return <FinovaLandingPage />;
}
