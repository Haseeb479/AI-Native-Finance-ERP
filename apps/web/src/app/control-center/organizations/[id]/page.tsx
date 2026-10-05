import { redirect } from "next/navigation";

export default async function LegacyControlCenterOrganizationPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  redirect(`/ops/companies/${encodeURIComponent(id)}`);
}
