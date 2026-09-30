import Card, { CardContent } from "@/components/Card";
import { cn, getAiConnectNoticeMarkup } from "@/lib/utils";
import { useState } from "@wordpress/element";
import TransitionWrapper from "./TransitionWrapper";

/**
 * The Themeisle SDK "Connect your AI agent" notice, rendered inside the page
 * like the Black Friday banner. The SDK script keeps handling the clicks: the
 * button opens its modal and the X records the dismissal, both by delegation
 * on the `data-ti-ai-notice` wrapper.
 */
const AiConnectNotice = ({ className }: { className?: string }) => {
  const markup = getAiConnectNoticeMarkup();
  const [dismissed, setDismissed] = useState(false);

  if (!markup || dismissed) {
    return null;
  }

  return (
    <TransitionWrapper from="fade" className={cn("delay-300 mb-6", className)}>
      <Card className="border-l-4 border-l-[#2271b1] border-y-[#2271b1]/30 border-r-[#2271b1]/30 shadow-md">
        <CardContent className="bg-[#e8f1fa] dark:bg-[#0f2a44]">
          <div className="ti-ai-notice relative pr-12" data-ti-ai-notice>
            <div dangerouslySetInnerHTML={{ __html: markup }} />
            <button
              type="button"
              className="notice-dismiss"
              onClick={() => setDismissed(true)}
            >
              <span className="screen-reader-text">Dismiss this notice.</span>
            </button>
          </div>
        </CardContent>
      </Card>
    </TransitionWrapper>
  );
}

export default AiConnectNotice;
