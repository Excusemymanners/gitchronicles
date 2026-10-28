import { Head } from '@inertiajs/react';

type Props = {
    mesaj: string;
};

export default function Home({ mesaj }: Props) {
    return (
        <>
            <Head title="Acasă" />
            <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-6">
                <h1 className="text-4xl font-semibold">GitChronicle</h1>
                <p className="text-gray-600">{mesaj}</p>
            </main>
        </>
    );
}
