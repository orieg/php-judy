#include <stdio.h>
#include <Judy.h>

void main() {

Word_t   Index;                     // array index
Word_t   Value;                     // array element value
Word_t * PValue;                    // pointer to array element value
int      Rc_int;                    // return code

Pvoid_t  PJLArray = (Pvoid_t) NULL; // initialize JudyL array

printf("start\n");

for (Index = 0; Index < 100; Index++)
{
    Pvoid_t  PJLSubArray = (Pvoid_t) NULL; // initialize JudyL sub-array

    Value = rand();

    // Set sub array
    JLI(PValue, PJLSubArray, 0);
    if (PValue == PJERR) {
        printf("malloc failure\n");
        break;
    }
    *PValue = Value;

    printf("%lu %lu\n", Index, Value);
    JLI(PValue, PJLArray, Index);
    if (PValue == PJERR) {
        printf("malloc failure\n");
        break;
    }
    *(Pvoid_t *)PValue = PJLSubArray; //store sub array
}

// Next, visit all the stored indexes in sorted order, first ascending,
// then descending, and delete each index during the descending pass.

printf("\nnext\n");

Index = 0;
JLF(PValue, PJLArray, Index);
while (PValue != NULL)
{
    if (*PValue & JLAP_INVALID) {
        printf("%lu %lu\n", Index, *PValue);
    } else {
        Word_t *PValue1;
        Pvoid_t PJLSubArray = (Pvoid_t) (*PValue & ~JLAP_INVALID);
        JLG(PValue1, PJLSubArray, 0);
        printf("%lu %lu\n", Index, *PValue1);
    }
    JLN(PValue, PJLArray, Index);
}

printf("\ndel\n");
Index = -1;
JLL(PValue, PJLArray, Index);
while (PValue != NULL)
{

    if (*PValue & JLAP_INVALID) {
        printf("%lu %lu\n", Index, *PValue);
    } else {
        Word_t *PValue1;
        Pvoid_t PJLSubArray = (Pvoid_t) (*PValue & ~JLAP_INVALID);
        JLG(PValue1, PJLSubArray, 0);
        printf("%lu %lu\n", Index, *PValue1);
        JLD(Rc_int, PJLSubArray, 0);
    }

    JLD(Rc_int, PJLArray, Index);
    if (Rc_int == JERR) {
        printf("malloc failure\n");
        break;
    }

    JLP(PValue, PJLArray, Index);
}

printf("\nend\n");

}
